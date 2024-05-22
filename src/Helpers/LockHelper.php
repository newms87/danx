<?php

namespace Newms87\DanxLaravel\Helpers;

use Exception;
use Newms87\DanxLaravel\Exceptions\LockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class LockHelper
{
	// Only lock a resource for 30 seconds by default
	const TTL = 30;

	// Only make this many attempts to acquire the resource lock before throwing an error
	const WAIT_TIME = 31;

	/**
	 * Attempt to acquire a lock for a given key. It will attempt $count number of times
	 * or throw an exception
	 *
	 * @param string|Model $key      The lock key. Will acquire a lock for anything using the same key
	 * @param int          $waitTime The amount of time to wait before throwing an exception
	 * @param int          $ttl      The locks time-to-live. Lock will expire after this many # of seconds
	 *
	 * @throws Throwable
	 */
	public static function acquire(Model|string $key, int $waitTime = LockHelper::WAIT_TIME, int $ttl = LockHelper::TTL)
	{
		$model = $key instanceof Model ? $key : null;
		$key   = self::resolveKey($key);

		$lock = Cache::lock($key, $ttl);

		try {
			$firstLock = $lock->get();

			// If we did not get the lock on the first attempt, block until we get the lock
			if (!$firstLock) {
				Log::debug("##### LOCK WAIT: $key");
				$lock->block($waitTime);
				Log::debug("##### LOCK ACQUIRED: $key");
			}

			// Always refresh the model, so we can guarantee we have the latest data after acquiring the lock
			$model?->refresh();
		} catch(Exception $exception) {
			throw new LockException($key, $exception);
		}
	}

	/**
	 * @param $key
	 * @param $ttl
	 * @return mixed
	 */
	public static function get($key, $ttl = LockHelper::TTL)
	{
		$key = self::resolveKey($key);

		return Cache::lock($key, $ttl)->get();
	}

	/**
	 * @param $key
	 */
	public static function release($key)
	{
		$key = self::resolveKey($key);
		Cache::lock($key)->forceRelease();
	}

	/**
	 * @param $key
	 * @return mixed|string
	 */
	public static function resolveKey($key)
	{
		if ($key instanceof Model) {
			return $key::class . ':' . $key->getKey();
		} else {
			return $key;
		}
	}
}
