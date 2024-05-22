<?php

namespace Newms87\DanxLaravel\Resources;

use Newms87\DanxLaravel\Models\Utilities\StoredFile;

/**
 * @mixin StoredFile
 */
class StoredFileResource extends ActionResource
{
	public static string $type = 'StoredFile';

	public function data(): array
	{
		/** @var StoredFile|array $file */
		$file = $this->resource;

		if ($file instanceof StoredFile && $file->id) {
			// TODO: For now, the thumb, optimized and original are all the same, soon we will add transcoding and thumbnails
			$optimizedFile = $file;
			$thumb         = $file;

			return [
				'id'         => $file->id,
				'filename'   => $file->filename,
				'url'        => $optimizedFile->url,
				'mime'       => $optimizedFile->mime,
				'size'       => $optimizedFile->size,
				'thumb'      => $thumb ? [
					'size' => $thumb->size,
					'url'  => $thumb->url,
					'mime' => $thumb->mime,
				] : null,
				'original'   => [
					'size' => $file->size,
					'url'  => $file->url,
					'mime' => $file->mime,
				],
				'location'   => $file->location,
				'created_at' => $file->created_at,
			];
		} elseif (is_array($file)) {
			$file = (object)$file;
		}

		return [
			'url'  => $file->url,
			'size' => $file->size,
			'mime' => $file->mime,
		];
	}
}
