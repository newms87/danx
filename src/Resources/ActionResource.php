<?php


namespace Newms87\Danx\Resources;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection as IlluminateCollection;

/**
 * @mixin Model
 * @property Model $resource
 */
abstract class ActionResource extends JsonResource
{
	protected static string $type           = '';
	public static array     $includedFields = [];

	protected array $resolvedData;

	public function __construct($resource)
	{
		if (!static::$type) {
			throw new Exception("static::\$type is required to be set on " . static::class);
		}

		parent::__construct($resource);

		$this->resolvedData = $this->data();
	}

	public static function make(...$parameters)
	{
		if (!$parameters || empty($parameters[0])) {
			return null;
		}

		return parent::make(...$parameters);
	}

	public static function collection($resource, $includedFields = []): AnonymousResourceCollection|null
	{
		static::$includedFields = $includedFields;

		if (!$resource || empty($resource[0])) {
			return null;
		}

		return parent::collection($resource);
	}

	public function isFieldIncluded($field): bool
	{
		return in_array('*', static::$includedFields) || in_array($field, static::$includedFields);
	}

	/**
	 * Checks if the field is requested for the resource and returns the value if so
	 */
	public function resolveField($field): mixed
	{
		if ($this->isFieldIncluded($field)) {
			return $this->{$field};
		}

		return null;
	}

	/**
	 * Checks if the relation is loaded and returns the relation or if the relation is not loaded but has been
	 * requested, return the default via the callback or just lazy load the relation if no callback given
	 */
	public function resolveFieldRelation(string $field, array|null $relations = null, $callback = null): Model|Collection|null
	{
		$relations = $relations ?: [$field];

		foreach($relations as $relation) {
			if ($this->relationLoaded($relation)) {
				return $this->getRelation($relation);
			}
		}

		// If the relation is not loaded, but has been requested, the callback will load the default relation
		if ($this->isFieldIncluded($field)) {
			return $callback ? $callback() : $this->{$relations[0]};
		}

		return null;
	}

	public function toArray($request)
	{
		return $this->resolvedData + [
				'__type'      => static::$type,
				'__timestamp' => request()->header('X-Timestamp') ?: LARAVEL_START,
			];
	}

	public function data(): array
	{
		throw new Exception('ActionResource requires the data method (in place of toArray). Please update ' . static::class);
	}
}
