<?php

namespace Newms87\Danx\Helpers;

use Illuminate\Support\Facades\Log;

class CacheHelper
{
	public static function cacheResult($params, $callback)
	{
		// Cache results for 24 hours
		$hash     = md5(serialize($params));
		$cacheKey = static::class . ':' . $hash;
		$results  = cache()->get($cacheKey);
		if (!$results) {
			$results = $callback($params);
			if ($results) {
				cache()->put($cacheKey, $results, 3600 * 24);
			}
		} else {
			Log::debug("USING CACHE: $cacheKey");
		}

		return $results;
	}
}
