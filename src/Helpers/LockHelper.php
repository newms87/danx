<?php

namespace Newms87\Danx\Helpers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Newms87\Danx\Exceptions\LockException;
use Throwable;

class LockHelper
{
	// Locks are only important to lock out other requests from modifying the same resource
	// We can keep track of the locks we have acquired in memory
	// This way we don't block ourselves if we try to acquire the same lock twice
	static array $acquiredLocks = [];

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
	public static function acquire(Model|string $key, int $waitTime = LockHelper::WAIT_TIME, int $ttl = LockHelper::TTL): bool
	{
		$model = $key instanceof Model ? $key : null;
		$key   = self::resolveKey($key);

		if (!empty(static::$acquiredLocks[$key])) {
			Log::debug("🔴🔒 LOCK ALREADY ACQUIRED: $key");
			// Always refresh the model, so we can guarantee we have the latest data after acquiring the lock
			$model?->refresh();

			return true;
		}

		$lock = Cache::lock($key, $ttl);

		try {
			$firstLock = $lock->get();

			// If we did not get the lock on the first attempt, block until we get the lock
			if (!$firstLock) {
				Log::debug("🟡🔒 WAIT: $key");
				$blockAt = microtime(true);
				$lock->block($waitTime);

				if (microtime(true) - $blockAt >= $waitTime) {
					Log::error("🔴🔒 TIMEOUT: $key");
				}
			}

			static::$acquiredLocks[$key] = true;
			Log::debug("🔴🔒 ACQUIRED: $key");

			// Always refresh the model, so we can guarantee we have the latest data after acquiring the lock
			$model?->refresh();
		} catch(Throwable $exception) {
			throw new LockException($key, $exception);
		}

		return true;
	}

	/**
	 * Get a lock on a key
	 */
	public static function get($key, $ttl = LockHelper::TTL)
	{
		$key = self::resolveKey($key);

		if (!empty(static::$acquiredLocks[$key])) {
			Log::debug("🔴🔒 LOCK ALREADY ACQUIRED: $key");

			return true;
		}

		$isAcquired = Cache::lock($key, $ttl)->get();

		if ($isAcquired) {
			static::$acquiredLocks[$key] = true;
			Log::debug("🔴🔒 ACQUIRED: $key");
		}

		return $isAcquired;
	}

	/**
	 * @param $key
	 */
	public static function release($key)
	{
		// IMPORTANT: Always set the lock to false after releasing it even if not in locally acquired locks in case of async processes acquiring and releasing the lock
		$key = self::resolveKey($key);
		Cache::lock($key)->forceRelease();
		static::$acquiredLocks[$key] = false;
		Log::debug("🟢🔒 RELEASED: $key");
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
