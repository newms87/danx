<?php

namespace Newms87\Danx\Traits;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait ActionModelTrait
{
	public function getDateFormat()
	{
		return 'Y-m-d H:i:s.u';
	}

	protected static function bootActionModelTrait()
	{
		static::saving(function ($model) {
			$xTimestamp = (float)request()->header('X-Timestamp');
			if ($xTimestamp) {
				$model->updated_at = Carbon::createFromTimestampMs($xTimestamp);
			}
		});
	}
}
