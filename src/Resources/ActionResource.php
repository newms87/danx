<?php


namespace Newms87\DanxLaravel\Resources;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Model
 * @property Model $resource
 */
abstract class ActionResource extends JsonResource
{
	protected static string $type = '';
	public static           $wrap = '';

	protected array $resolvedData;

	public function __construct($resource)
	{
		if (!static::$type) {
			throw new Exception("static::\$type is required to be set on " . static::class);
		}

		parent::__construct($resource);

		$this->resolvedData = $this->data();
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
