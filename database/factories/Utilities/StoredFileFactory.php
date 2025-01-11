<?php

namespace Database\Factories\Utilities;

use Illuminate\Database\Eloquent\Factories\Factory;
use Newms87\Danx\Models\Utilities\StoredFile;

class StoredFileFactory extends Factory
{
	protected $model = StoredFile::class;

	public function definition(): array
	{
		return [
			'disk'                    => 'local',
			'filepath'                => fake()->file('public/storage', 'storage/app/public', false),
			'filename'                => fake()->unique()->word . '.jpg',
			'url'                     => fake()->url,
			'mime'                    => 'image/jpg',
			'size'                    => 1000,
			'exif'                    => null,
			'meta'                    => null,
			'location'                => null,
			'transcode_name'          => null,
			'original_stored_file_id' => null,
		];
	}
}
