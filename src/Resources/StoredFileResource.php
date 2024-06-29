<?php

namespace Newms87\Danx\Resources;

use Illuminate\Database\Eloquent\Model;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

class StoredFileResource extends ActionResource
{
	/**
	 * @param StoredFile $model
	 */
	public static function data(Model $model): array
	{
		$data = [
			'id'         => $model->id,
			'filename'   => $model->filename,
			'url'        => $model->url,
			'mime'       => $model->mime,
			'size'       => $model->size,
			'location'   => $model->location,
			'meta'       => $model->meta,
			'created_at' => $model->created_at,
		];

		// If this file is not a transcoded file, add a thumb and optimized transcode entry
		if (!$model->original_stored_file_id && $model->isPdf()) {
			$thumb = $model->transcodes()->where('transcode_name', TranscodeFileService::TRANSCODE_PDF_TO_IMAGES)->first();

			if ($thumb) {
				$data['thumb'] = StoredFileResource::make($thumb);

				// XXX: TODO For now, optimized and thumb are the same, eventually we will want to optimize the thumb
				$data['optimized'] = StoredFileResource::make($thumb);
			}
		}

		return $data;
	}

	/**
	 * @param StoredFile $model
	 */
	public static function details(Model $model): array
	{
		return static::make($model, [
			'transcodes' => StoredFileResource::collection($model->transcodes()->get()),
		]);
	}
}
