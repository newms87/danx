<?php

namespace Newms87\Danx\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Newms87\Danx\Api\ConvertApi\ConvertApi;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Repositories\FileRepository;
use Throwable;

class TranscodeFileService
{
	const string
		TRANSCODE_PDF_TO_IMAGES = 'pdf-to-images',
		TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS = 'image-to-vertical-chunks';

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

	public function pdfToImages(StoredFile $storedFile)
	{
		Log::debug("$storedFile creating images from PDF");

		$transcodeName = self::TRANSCODE_PDF_TO_IMAGES;

		$transcodes = $storedFile->transcodes()->where('transcode_name', $transcodeName)->get();

		if ($transcodes->isNotEmpty()) {
			Log::debug("Already has $transcodeName transcodes");

			return $transcodes;
		}

		$result = app(ConvertApi::class)->pdfToImage($storedFile->url);

		if (!isset($result['Files'])) {
			throw new ApiException("Convert API did not return any files for PDF to Images transcode\n\n" . json_encode($result));
		}

		foreach($result['Files'] as $image) {
			$transcodedFile = $this->storeTranscodedFile($storedFile, $transcodeName, $image['FileName'], base64_decode($image['FileData']));
			$transcodes->push($transcodedFile);
		}

		return $transcodes;
	}

	/**
	 * @param StoredFile $storedFile The original file to create transcoded chunks
	 * @param int        $maxHeight  The maximum height for each chunk (the last chunk will match the remaining height)
	 * @param int        $padding    The vertical padding to include on each chunk (ie: the top of the image will
	 *                               include extra rows of pixels overlapping with the previous chunk amount so content
	 *                               such as text is still readable in each chunk)
	 */
	public function imageToVerticalChunks(StoredFile $storedFile, int $maxHeight = 1024, int $padding = 50): Collection
	{
		Log::debug("$storedFile creating vertical chunks: height = $maxHeight px, padding = $padding px");

		$transcodeName = self::TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS;

		$transcodes = $storedFile->transcodes()->where('transcode_name', $transcodeName)->get();

		if ($transcodes->isNotEmpty()) {
			Log::debug("Already has $transcodeName transcodes");

			return $transcodes;
		}

		try {
			$contents = file_get_contents($storedFile->url);

			if (!$contents) {
				throw new ApiException("Could not read file contents from $storedFile->url");
			}

			// First try to use Imagick to read the image, if that fails use GD
			try {
				$manager = ImageManager::imagick();
				$image   = $manager->read($contents);
			} catch(Throwable $throwable) {
				Log::warning("Imagick Error reading image $storedFile->url:\n" . $throwable->getMessage());

				try {
					$manager = ImageManager::gd();
					$image   = $manager->read($contents);
				} catch(Throwable $throwable) {
					Log::error("GD Error reading image $storedFile->url:\n" . $throwable->getMessage());
					throw $throwable;
				}
			}

			$imageWidth  = $image->width();
			$imageHeight = $image->height();

			// Loop through the image and create chunks
			for($y = 0; $y < $imageHeight; $y += $maxHeight) {
				$data = $manager->read($contents)
					->crop($imageWidth, min($maxHeight, $imageHeight - $y), 0, max(0, $y - $padding))
					->toJpeg()
					->toString();
				$transcodes->push($this->storeTranscodedFile($storedFile, $transcodeName, $y . '.jpg', $data));
			}
		} catch(Throwable $throwable) {
			Log::error("Error vertical chunking $storedFile->url:\n" . $throwable->getMessage());
			throw $throwable;
		}

		return $transcodes;
	}
}
