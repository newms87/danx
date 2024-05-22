<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Str;

/**
 * Check if authenticated user has permissions
 *
 * @param                           $permission
 * @param bool                      $requireAll
 * @param Authenticatable|User|null $user
 * @return bool
 */
if (!function_exists('can')) {
	function can(array|string $permission, $requireAll = false, Authenticatable $user = null)
	{
		$user = $user ?? user();

		if (!$user) {
			return false;
		}

		if (is_string($permission)) {
			$permission = Str::of($permission)->explode('|');
		}

		$can = $user->can($permission);

		if ($requireAll) {
			return !$can->contains(false);
		}

		return $can->contains(true);
	}
}

if (!function_exists('user')) {
	/**
	 * Returns an authenticated User
	 *
	 * @return Authenticatable|\App\Models\User|null
	 */
	function user()
	{
		return auth()->guard()?->user();
	}
}

if (!function_exists('array_is_numeric')) {
	function array_is_numeric($array)
	{
		foreach($array as $key => $value) {
			if (!is_int($key)) {
				return false;
			}
		}

		return true;
	}
}

if (!function_exists('app_url')) {
	function app_url($path = '', $params = [])
	{
		$ug = new UrlGenerator(app('router')->getRoutes(), request());
		$ug->forceRootUrl(config('app.spa_url'));

		return $ug->to($path, $params, config('app.forceHttps'));
	}
}

if (!function_exists('api_url')) {
	function api_url($path = '', $params = [], $short = false)
	{
		$baseUrl = $short && config('app.short_url') ? config('app.short_url') : config('app.url');
		$ug      = new UrlGenerator(app('router')->getRoutes(), request());
		$ug->forceRootUrl($baseUrl);

		return $ug->to($path, $params, $short ? config('app.forceHttpsShortUrl') : config('app.forceHttps'));
	}
}

if (!function_exists('api_short_url')) {
	function api_short_url($path = '', $params = [])
	{
		return api_url($path, $params, true);
	}
}

if (!function_exists('uuid')) {
	/**
	 * Creates a UUID v4 string
	 *
	 * @return string
	 */
	function uuid()
	{
		return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

			// 32 bits for "time_low"
			mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF),

			// 16 bits for "time_mid"
			mt_rand(0, 0xFFFF),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand(0, 0x0FFF) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand(0, 0x3FFF) | 0x8000,

			// 48 bits for "node"
			mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF)
		);
	}
}

if (!function_exists('carbon')) {
	function carbon($date = null, $tz = null)
	{
		return Carbon\Carbon::parse($date, $tz);
	}
}
