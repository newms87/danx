<?php

namespace Newms87\Danx\Resources;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

class StoredFileResource extends ActionResource
{
	public static function data(StoredFile $storedFile): array
	{
		return [
			'id'          => $storedFile->id,
			'filename'    => $storedFile->filename,
			'url'         => $storedFile->url,
			'mime'        => $storedFile->mime,
			'size'        => $storedFile->size,
			'location'    => $storedFile->location,
			'meta'        => $storedFile->meta,
			'page_number' => $storedFile->page_number,
			'created_at'  => $storedFile->created_at,
			'thumb'       => fn($fields) => static::getThumb($storedFile),
			'optimized'   => fn($fields) => static::getThumb($storedFile),
			'transcodes'  => fn($fields) => StoredFileResource::collection($storedFile->transcodes()->get(), $fields),
		];
	}

	/**
	 * Get the thumb for a stored file.
	 *
	 * NOTE: Only applicable to PDF files for now
	 */
	public static function getThumb(?StoredFile $storedFile): null|array
	{
		if (!$storedFile) {
			return null;
		}

		$thumb = null;

		if ($storedFile->isPdf()) {
			if ($storedFile->original_stored_file_id) {
				$thumb = $storedFile;
			} else {
				$thumb = $storedFile->transcodes()->where('transcode_name', TranscodeFileService::TRANSCODE_PDF_TO_IMAGES)->first();
			}
		}

		if (!$thumb) {
			return null;
		}

		return [
			'id'       => $thumb->id,
			'url'      => $thumb->url,
			'filename' => $thumb->filename,
			'mime'     => $thumb->mime,
			'size'     => $thumb->size,
		];
	}
}
