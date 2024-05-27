<?php

namespace Newms87\Danx\Resources;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

/**
 * @mixin StoredFile
 */
class StoredFileResource extends ActionResource
{
	public static string $type = 'StoredFile';

	public function data(): array
	{
		/** @var StoredFile|array $storedFile */
		$storedFile = $this->resource;

		if ($storedFile instanceof StoredFile && $storedFile->id) {
			$data = [
				'id'         => $storedFile->id,
				'filename'   => $storedFile->filename,
				'url'        => $storedFile->url,
				'mime'       => $storedFile->mime,
				'size'       => $storedFile->size,
				'location'   => $storedFile->location,
				'created_at' => $storedFile->created_at,
			];

			// If this file is not a transcoded file, add a thumb and optimized transcode entry
			if (!$storedFile->original_stored_file_id && $storedFile->isPdf()) {
				$thumb = $storedFile->transcodes()->where('transcode_name', TranscodeFileService::TRANSCODE_PDF_TO_IMAGES)->first();

				if ($thumb) {
					$data['thumb'] = StoredFileResource::make($thumb);

					// For now, optimized and thumb are the same, eventually we will want to optimize the thumb
					$data['optimized'] = StoredFileResource::make($thumb);
				}
			}

			return $data;
		} elseif (is_array($storedFile)) {
			$storedFile = (object)$storedFile;
		}

		return [
			'url'  => $storedFile->url,
			'size' => $storedFile->size,
			'mime' => $storedFile->mime,
		];
	}
}
