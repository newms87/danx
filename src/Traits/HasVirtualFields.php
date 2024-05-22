<?php

namespace Newms87\DanxLaravel\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait HasVirtualFields
 *
 * @mixin Model
 */
trait HasVirtualFields
{
	protected $__refreshVirtual = false;

	protected $__savedVirtualFields = [];

	public static function bootHasVirtualFields()
	{
		static::saving(function (Model $model) {
			if (!isset($model->virtual)) {
				throw new Exception("You must include 'protected \$virtual = ['field_name', ...];' in " . get_class($model) . ' to use the HasVirtualFields trait');
			}

			$model->__refreshVirtual     = false;
			$model->__savedVirtualFields = [];

			// Unset all the virtual fields
			foreach($model->virtual as $field => $dependencies) {
				foreach($dependencies as $dependency) {
					if ($model->isDirty($dependency)) {
						$model->__refreshVirtual = true;
						break;
					}
				}

				$attributes = $model->getAttributes();

				if (isset($attributes[$field])) {
					$model->__savedVirtualFields[$field] = $attributes[$field];
					unset($attributes[$field]);
				}

				$model->setRawAttributes($attributes);
			}
		});

		static::saved(function (Model $model) {
			/* @var Model|static $model */
			if ($model->__refreshVirtual) {
				// Be sure to reload the Virtual Columns from the database
				$freshAttributes = $model->fresh()->getAttributes();

				$model->setRawAttributes($freshAttributes, true);
			} else {
				foreach($model->__savedVirtualFields as $field => $value) {
					$model->attributes[$field] = $value;
				}
			}
		});
	}
}
