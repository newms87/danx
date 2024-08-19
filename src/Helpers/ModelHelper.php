<?php

namespace Newms87\Danx\Helpers;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class ModelHelper
{
	/**
	 * Searches the database for the next available model name
	 */
	public static function getNextModelName(Model $model, $fieldName = 'name')
	{
		$count    = 0;
		$baseName = trim(preg_replace("/\\(\\d+\\)$/", '', trim($model->{$fieldName})));

		do {
			$count++;
			$newName = "$baseName ($count)";
		} while($model::query()->where($fieldName, $newName)->exists());

		return $newName;
	}

	public static function getModelsWithTrait($trait)
	{
		$models    = [];
		$modelPath = app_path('Models');

		$finder = new Finder();
		$finder->files()->in($modelPath)->name('*.php');

		foreach($finder as $file) {
			$className = 'App\\Models\\' . Str::replaceLast(
					'.php', '',
					Str::after($file->getPathname(), $modelPath . DIRECTORY_SEPARATOR)
				);

			$className = str_replace(DIRECTORY_SEPARATOR, '\\', $className);

			if (class_exists($className)) {
				$reflection = new ReflectionClass($className);
				if ($reflection->isSubclassOf('Illuminate\Database\Eloquent\Model')
					&& in_array($trait, class_uses_recursive($className))
				) {
					$models[] = $className;
				}
			}
		}

		return $models;
	}
}
