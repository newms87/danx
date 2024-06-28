<?php

namespace Newms87\Danx\Services\TranscodeFile;

use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Models\Utilities\StoredFile;
use Throwable;

/**
 * Breaks an image into vertical chunks with a fixed max height.
 * If you have a very long image, this will break it into
 * multiple images with the same width but a max height.
 */
class ImageToVerticalChunksTranscoder implements FileTranscoderContract
{
	/**
	 * Options
	 *  - maxHeight: The maximum height for each chunk (the last chunk will match the remaining height)
	 *  - padding: The vertical padding to include on each chunk (ie: the top of the image will include extra rows of
	 *             pixels overlapping with the previous chunk amount so content such as text is still readable in each
	 *             chunk)
	 */
	public function run(StoredFile $storedFile, array $options = []): array
	{
		$options += [
			'maxHeight' => 1024,
			'padding'   => 50,
		];

		$maxHeight = $options['maxHeight'];
		$padding   = $options['padding'];

		Log::debug("Creating vertical chunks: height = $maxHeight px, padding = $padding px");

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

			$transcodedFiles = [];

			// Loop through the image and create chunks
			for($y = 0; $y < $imageHeight; $y += $maxHeight) {
				$data = $manager->read($contents)
					->crop($imageWidth, min($maxHeight, $imageHeight - $y), 0, max(0, $y - $padding))
					->toJpeg()
					->toString();

				$transcodedFiles[] = [
					'filename' => $y . '.jpg',
					'data'     => $data,
				];
			}

			return $transcodedFiles;
		} catch(Throwable $throwable) {
			Log::error("Error vertical chunking $storedFile->url:\n" . $throwable->getMessage());
			throw $throwable;
		}
	}
}
