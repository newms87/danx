<?php

use Illuminate\Routing\UrlGenerator;

/**
 * Check if authenticated user has permissions
 */
if (!function_exists('can')) {
	function can(array|string $permission): bool
	{
		return user()?->can($permission) ?: false;
	}
}

if (!function_exists('user')) {
	/**
	 * Returns an authenticated User
	 */
	function user()
	{
		return auth()->guard()?->user();
	}
}

if (!function_exists('is_associative_array')) {
	function is_associative_array($array): bool
	{
		if (!is_array($array)) {
			return false;
		}

		return array_keys($array) !== range(0, count($array) - 1);
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
	function carbon($date = null, $tz = null): Carbon\Carbon
	{
		if (is_string($date)) {
			// sanitize and reformat unparseable dates
			$date = preg_replace('/\s+/', ' ', $date);
			$date = str_replace('-', '/', $date);
		} elseif (is_numeric($date)) {
			$date = '@' . $date;
		}

		return Carbon\Carbon::parse($date, $tz);
	}
}

if (!function_exists('now_ms')) {
	function now_ms(): string
	{
		return now()->format('Y-m-d H:i:s.u');
	}
}

if (!function_exists('team')) {
	/**
	 * Returns the current team context, or resolves a team by ID if provided
	 */
	function team($teamId = null): ?object
	{
		$teamClass = config('danx.models.team', \Newms87\Danx\Models\Team\Team::class);

		// If a team ID is provided, resolve and return that team directly
		if ($teamId) {
			return $teamClass ? $teamClass::find($teamId) : null;
		}

		$user = user();

		if (!$user) {
			return null;
		}

		if (\Newms87\Danx\Jobs\Job::$runningJob) {
			$teamId = \Newms87\Danx\Jobs\Job::$runningJob->team_id;
			if ($teamId && $user->currentTeam?->id !== $teamId) {
				$user->currentTeam = $teamClass::find($teamId);
			}
		}

		// Fallback: if currentTeam still not set, try token or user's teams
		if (!$user->currentTeam) {
			$token = $user->currentAccessToken();

			// The token name matches the name of the team the user is authorized to access
			// TransientToken (used in tests) doesn't have a name property
			if ($token && $token instanceof \Laravel\Sanctum\PersonalAccessToken) {
				$user->setCurrentTeam($token->name);
			} elseif (method_exists($user, 'teams')) {
				$user->setCurrentTeam($user->teams()->first()?->uuid);
			}
		}

		return $user->currentTeam;
	}
}
