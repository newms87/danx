<?php

namespace Newms87\DanxLaravel\Eloquent\Traits;

use Newms87\DanxLaravel\Models\Ref;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * This trait is to be used with the 'ref' field $table->string('ref')->unique() schema definition
 * @mixin Model
 */
trait RefModelTrait
{
	static string $refPrefix = '';

	/**
	 * This function overwrites the default boot static method of Eloquent models. It will hook
	 * the creation event with a simple closure to generate a new increment Unique Ref
	 *
	 * @throws Throwable
	 */
	public static function bootRefModelTrait()
	{
		static::creating(function (Model $model) {
			if (!$model->ref) {
				$model->ref = static::generateRef();
			}
		});
	}

	/**
	 * Generates the next sequential Ref ID for this Model
	 *
	 * @return string
	 *
	 * @throws Throwable
	 */
	public static function generateRef()
	{
		return Ref::generate(static::$refPrefix);
	}

	/**
	 * @return $this
	 * @throws Throwable
	 */
	public function assignRef()
	{
		$this->ref = static::generateRef();

		return $this;
	}
}
