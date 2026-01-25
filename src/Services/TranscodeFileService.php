<?php

namespace Newms87\Danx\Services;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Traits\HasDebugLogging;
use Newms87\Danx\Jobs\TranscodeDataUrlToStoredFileJob;
use Newms87\Danx\Jobs\TranscodeStoredFileJob;
use Newms87\Danx\Models\Job\JobBatch;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Repositories\FileRepository;
use Newms87\Danx\Services\TranscodeFile\FileTranscoderAbstract;
use Newms87\Danx\Services\TranscodeFile\ImageToVerticalChunksTranscoder;
use Newms87\Danx\Services\TranscodeFile\PdfToImagesTranscoder;

class TranscodeFileService
{
	use HasDebugLogging;

	const string
		STATUS_PENDING = 'Pending',
		STATUS_IN_PROGRESS = 'In Progress',
		STATUS_TIMEOUT = 'Timeout',
		STATUS_COMPLETE = 'Complete';

	const string
		TRANSCODE_PDF_TO_IMAGES = 'PDF to Images',
		TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS = 'Image to Vertical Chunks';

	const array TRANSCODERS = [
		self::TRANSCODE_PDF_TO_IMAGES            => PdfToImagesTranscoder::class,
		self::TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS => ImageToVerticalChunksTranscoder::class,
	];

	public function storeTranscodedFile(StoredFile $storedFile, $transcodeName, $filename, $data, int $pageNumber = null): StoredFile
	{
		$dir                                     = $storedFile->id;
		$filepath                                = "transcodes/$transcodeName/$dir/$filename";
		$transcodedFile                          = app(FileRepository::class)->createFileWithContents($filepath, $data);
		$transcodedFile->team_id                 = $storedFile->team_id;
		$transcodedFile->original_stored_file_id = $storedFile->id;
		$transcodedFile->transcode_name          = $transcodeName;
		$transcodedFile->page_number             = $pageNumber ?? $storedFile->page_number;
		$transcodedFile->save();

		return $transcodedFile;
	}

	/**
	 * Returns a Transcoder instance for the given transcode name
	 */
	public function getTranscoder(string $transcodeName): FileTranscoderAbstract
	{
		$transcoder = self::TRANSCODERS[$transcodeName] ?? null;

		if (!$transcoder) {
			throw new ApiException("Transcoder not found: $transcodeName");
		}

		return app($transcoder);
	}

	/**
	 * Dispatch a transcode job for the stored file
	 */
	public function dispatch(string $transcodeName, StoredFile $storedFile, array $options = []): TranscodeStoredFileJob
	{
		$storedFile->setMeta('transcodes', [
			$transcodeName => [
				'status'       => self::STATUS_PENDING,
				'progress'     => 0,
				'requested_at' => now(),
				'started_at'   => null,
				'timeout_at'   => now()->addSeconds($this->getTranscoder($transcodeName)->getTimeout($storedFile)),
				'completed_at' => null,
			],
		])->save();

		return (new TranscodeStoredFileJob($storedFile, $transcodeName))->dispatch();
	}

	/**
	 * Perform the transcode on the stored file
	 */
	public function transcode(string $transcodeName, StoredFile $storedFile, array $options = []): Collection
	{
		static::logDebug("Transcode $transcodeName: $storedFile");

		$transcodes = $storedFile->transcodes()->where('transcode_name', $transcodeName)->get();

		if ($transcodes->isNotEmpty()) {
			static::logDebug("Already has $transcodeName transcodes");

			return $transcodes;
		}

		$this->start($storedFile, $transcodeName);

		$transcoder      = $this->getTranscoder($transcodeName);
		$transcodedFiles = $transcoder->run($storedFile, $options);

		// If this service uses data URLs instead of the raw file data, we can run this in parallel and download the data from the URLs in a job instead of all in a single execution
		if ($transcoder->usesDataUrls()) {
			$batchJobs = [];
			foreach($transcodedFiles as $transcodedFile) {
				$batchJobs[] = (new TranscodeDataUrlToStoredFileJob($storedFile, $transcodeName, $transcodedFile));
			}

			$storedFileId = $storedFile->id;
			JobBatch::createForJobs("Store transcoded files for $transcodeName", $batchJobs, function () use ($storedFileId, $transcodeName) {
				app(TranscodeFileService::class)->complete(StoredFile::find($storedFileId), $transcodeName);
			});
		} else {
			// if we are dealing with raw data, the data is already loaded into memory and needs to be saved to a file immediately all in one execution (too expensive and small savings to try to run in jobs in parallel)
			foreach($transcodedFiles as $transcodedFile) {
				$transcodedFile = $this->storeTranscodedFile($storedFile, $transcodeName, $transcodedFile['filename'], $transcodedFile['data'], $transcodedFile['page_number'] ?? null);
				$transcodes->push($transcodedFile);
			}

			$this->complete($storedFile, $transcodeName);
		}

		return $transcodes;
	}

	public function moveDataUrlToStoredFile(StoredFile $storedFile, string $transcodeName, array $transcodedFile): StoredFile
	{
		$filename   = $transcodedFile['filename'] ?? null;
		$url        = $transcodedFile['url'] ?? null;
		$pageNumber = $transcodedFile['page_number'] ?? null;

		if (!$url) {
			throw new Exception("Transcoded file does not have a URL");
		}

		if (!$filename) {
			throw new Exception("Transcoded file does not have a filename");
		}

		$data = file_get_contents($url);

		return $this->storeTranscodedFile($storedFile, $transcodeName, $filename, $data, $pageNumber);
	}

	/**
	 * Mark the transcoded file as started
	 */
	public function start(StoredFile $storedFile, $transcodeName): void
	{
		$transcoder = $this->getTranscoder($transcodeName);
		$progress   = $transcoder->startingProgress($storedFile);
		$estimate   = $transcoder->timeEstimate($storedFile);

		$storedFile->setMeta('transcodes', [
			$transcodeName => [
				'status'       => self::STATUS_IN_PROGRESS,
				'progress'     => $progress,
				'estimate_ms'  => $estimate,
				'started_at'   => now(),
				'timeout_at'   => now()->addSeconds($this->getTranscoder($transcodeName)->getTimeout($storedFile)),
				'completed_at' => null,
			],
		])->save();
	}

	/**
	 * Mark the transcoded file as completed
	 */
	public function complete(StoredFile $storedFile, $transcodeName): void
	{
		$storedFile->setMeta('transcodes', [
			$transcodeName => [
				'status'       => self::STATUS_COMPLETE,
				'progress'     => 100,
				'completed_at' => now(),
			],
		])->save();
	}
}
