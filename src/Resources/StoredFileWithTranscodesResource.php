<?php

namespace Newms87\Danx\Resources;

use Newms87\Danx\Models\Utilities\StoredFile;

/**
 * @mixin StoredFile
 */
class StoredFileWithTranscodesResource extends StoredFileResource
{
	public static string $type = 'StoredFile';

	public function data(): array
	{
		/** @var StoredFile|array $storedFile */
		$storedFile = $this->resource;

		if ($storedFile instanceof StoredFile && $storedFile->id) {
			$transcodes = $storedFile->transcodes()->get();

			return [
					'transcodes' => $transcodes->isNotEmpty() ? StoredFileResource::collection($transcodes) : null,
				] + parent::data();
		}

		return parent::data();
	}
}
