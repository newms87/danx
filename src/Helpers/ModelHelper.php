<?php

namespace Newms87\Danx\Helpers;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class ModelHelper
{
	/**
	 * Searches the database for the next available value for a field
	 */
	public static function getNextUniqueValue(Builder $query, $fieldName, $value): string
	{
		$count     = 0;
		$baseValue = trim(preg_replace("/\\(\\d+\\)$/", '', trim($value)));
		$newValue  = $baseValue;

		while($query->clone()->where($fieldName, $newValue)->exists()) {
			$count++;
			$newValue = "$baseValue ($count)";
		}

		return $newValue;
	}

	/**
	 * Searches the database for the next available model name
	 */
	public static function getNextModelName(Model $model, $fieldName = 'name', $filter = []): string
	{
		return static::getNextUniqueValue($model::query()->filter($filter), $fieldName, $model->{$fieldName});
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
