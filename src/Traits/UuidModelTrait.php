<?php

namespace Newms87\DanxLaravel\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * This trait is to be used with the default $table->uuid('id')->primary() schema definition
 * @mixin Model
 */
trait UuidModelTrait
{
	/**
	 * This function overwrites the default boot static method of Eloquent models. It will hook
	 * the creation event with a simple closure to insert the UUID
	 */
	public static function bootUuidModelTrait()
	{
		static::creating(function (Model $model) {
			/** @var UuidModelTrait $model */
			// Only generate UUID if it wasn't set by already.
			if (!isset($model->attributes[$model->getKeyName()])) {
				// This is necessary because on \Illuminate\Database\Eloquent\Model::performInsert
				// will not check for $this->getIncrementing() but directly for $this->incrementing
				$model->incrementing = false;
				$model->assignUuid();
			}
		});
	}

	/*
	 * This function is used internally by Eloquent models to test if the model has auto increment value
	 * @returns bool Always false
	 */

	public static function generateUuid()
	{
		return Str::orderedUuid()->toString();
	}

	/**
	 * @return $this|static
	 */
	public function assignUuid()
	{
		$this->attributes[$this->getKeyName()] = self::generateUuid();

		return $this;
	}

	/**
	 * @return false
	 */
	public function getIncrementing()
	{
		return false;
	}

	/**
	 * @return false|string
	 */
	public function getShortUuidAttribute()
	{
		return substr($this->attributes[$this->getKeyName()], 24);
	}
}
