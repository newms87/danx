<?php

namespace Newms87\Danx\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Jobs\TranscodeStoredFileJob;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Repositories\FileRepository;
use Newms87\Danx\Services\TranscodeFile\ImageToVerticalChunksTranscoder;
use Newms87\Danx\Services\TranscodeFile\PdfToImagesTranscoder;

class TranscodeFileService
{
	const string
		STATUS_PENDING = 'Pending',
		STATUS_IN_PROGRESS = 'In Progress',
		STATUS_COMPLETE = 'Complete';

	const string
		TRANSCODE_PDF_TO_IMAGES = 'pdf-to-images',
		TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS = 'image-to-vertical-chunks';

	const array TRANSCODERS = [
		self::TRANSCODE_PDF_TO_IMAGES            => PdfToImagesTranscoder::class,
		self::TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS => ImageToVerticalChunksTranscoder::class,
	];

	public function storeTranscodedFile(StoredFile $storedFile, $transcodeName, $filename, $data)
	{
		$dir                                     = $storedFile->id;
		$filepath                                = "transcodes/$transcodeName/$dir/$filename";
		$transcodedFile                          = app(FileRepository::class)->createFileWithContents($filepath, $data);
		$transcodedFile->original_stored_file_id = $storedFile->id;
		$transcodedFile->transcode_name          = $transcodeName;
		$transcodedFile->save();

		return $transcodedFile;
	}

	/**
	 * Dispatch a transcode job for the stored file
	 */
	public function dispatch(string $transcodeName, StoredFile $storedFile, array $options = []): TranscodeStoredFileJob
	{
		$storedFile->setMeta('transcodes', [
			$transcodeName => [
				'status'       => self::STATUS_PENDING,
				'requested_at' => now(),
				'started_at'   => null,
				'completed_at' => null,
			],
		])->save();

		return (new TranscodeStoredFileJob($storedFile, $transcodeName, $options))->dispatch();
	}

	/**
	 * Perform the transcode on the stored file
	 */
	public function transcode(string $transcodeName, StoredFile $storedFile, array $options = []): Collection
	{
		Log::debug("Transcode $transcodeName: $storedFile");

		$transcodes = $storedFile->transcodes()->where('transcode_name', $transcodeName)->get();

		if ($transcodes->isNotEmpty()) {
			Log::debug("Already has $transcodeName transcodes");

			return $transcodes;
		}

		$this->start($storedFile, $transcodeName);

		$transcoder = self::TRANSCODERS[$transcodeName] ?? null;

		if (!$transcoder) {
			throw new ApiException("Transcoder not found: $transcodeName");
		}

		$transcodedFiles = app($transcoder)->run($storedFile, $options);

		foreach($transcodedFiles as $transcodedFile) {
			$transcodedFile = $this->storeTranscodedFile($storedFile, $transcodeName, $transcodedFile['filename'], $transcodedFile['data']);
			$transcodes->push($transcodedFile);
		}

		$this->complete($storedFile, $transcodeName);

		return $transcodes;
	}

	/**
	 * Mark the transcoded file as started
	 */
	public function start(StoredFile $storedFile, $transcodeName): void
	{
		$storedFile->setMeta('transcodes', [
			$transcodeName => [
				'status'       => self::STATUS_IN_PROGRESS,
				'started_at'   => now(),
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
				'completed_at' => now(),
			],
		])->save();
	}
}
