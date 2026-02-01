<?php

namespace Newms87\Danx\Helpers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Newms87\Danx\Exceptions\LockException;
use Newms87\Danx\Exceptions\StaleLockException;
use Throwable;

/**
 * Helper for acquiring and releasing distributed locks using Laravel's cache driver.
 *
 * NOTE: This class intentionally does NOT use HasDebugLogging trait.
 * LockHelper logging is handled as a special case - AuditLogHandler has a
 * re-entrancy guard that prevents infinite loops when LockHelper logs during
 * lock acquisition/release for audit_request records.
 */
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
				Log::debug("🟡🔒 WAIT: $key ($waitTime s)");
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
			throw new LockException($key, $waitTime, $exception);
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

	/**
	 * Acquire a timestamped lock for event deduplication.
	 *
	 * This method is designed for scenarios where multiple events may be queued
	 * for the same resource, and only the most recent should be sent. It uses
	 * timestamps to determine which request should proceed:
	 *
	 * - If our timestamp < last_sent_at: We're stale (newer event already sent) → StaleLockException
	 * - If our timestamp < lock's timestamp: We're stale (newer event is sending) → StaleLockException
	 * - If our timestamp > lock's timestamp: We're fresher → wait for lock release
	 * - If lock is free: Acquire and proceed
	 *
	 * @param  Model|string  $key        The lock key
	 * @param  string        $timestamp  ISO8601 timestamp when this request was initiated
	 * @param  int           $waitTime   Max seconds to wait if fresher (default 5)
	 * @param  int           $pollMs     Polling interval in ms (default 100)
	 * @param  int           $ttl        Lock TTL in seconds (default 60)
	 * @return bool True if lock acquired
	 *
	 * @throws StaleLockException If our timestamp < lock's timestamp (we're stale)
	 * @throws LockException If we timeout waiting for lock
	 */
	public static function acquireWithTimestamp(
		Model|string $key,
		string $timestamp,
		int $waitTime = 5,
		int $pollMs = 100,
		int $ttl = 60
	): bool {
		$key = self::resolveKey($key);
		$lockKey = "ts-lock:$key";
		$lastSentKey = "ts-last-sent:$key";

		// Check if we're already stale compared to last sent
		$lastSentAt = Cache::get($lastSentKey);
		if ($lastSentAt && $timestamp < $lastSentAt) {
			throw new StaleLockException($key, $timestamp, $lastSentAt);
		}

		$startTime = microtime(true);
		$pollSeconds = $pollMs / 1000;

		while (true) {
			// Try to acquire the lock with our timestamp as the value
			$acquired = Cache::add($lockKey, $timestamp, $ttl);

			if ($acquired) {
				Log::debug("🔴🔒 TS-ACQUIRED: $key (ts=$timestamp)");

				return true;
			}

			// Lock is held - check the holder's timestamp
			$lockedTimestamp = Cache::get($lockKey);

			if (!$lockedTimestamp) {
				// Lock was just released, try again
				continue;
			}

			// If we're stale compared to current holder, abort
			if ($timestamp < $lockedTimestamp) {
				throw new StaleLockException($key, $timestamp, $lockedTimestamp);
			}

			// We're fresher - wait for the lock to be released
			$elapsed = microtime(true) - $startTime;
			if ($elapsed >= $waitTime) {
				Log::error("🔴🔒 TS-TIMEOUT: $key (waited {$waitTime}s)");
				throw new LockException($key, $waitTime);
			}

			Log::debug("🟡🔒 TS-WAIT: $key (polling, elapsed=" . round($elapsed, 2) . "s)");
			usleep($pollMs * 1000);
		}
	}

	/**
	 * Release a timestamped lock and record when it was sent.
	 *
	 * @param  Model|string  $key        The lock key
	 * @param  int           $lastSentTtl  TTL for the last_sent_at marker (default 60 seconds)
	 */
	public static function releaseWithTimestamp(Model|string $key, int $lastSentTtl = 60): void
	{
		$key = self::resolveKey($key);
		$lockKey = "ts-lock:$key";
		$lastSentKey = "ts-last-sent:$key";

		// Record when we sent, so future stale events can be rejected
		Cache::put($lastSentKey, now()->toIso8601String(), $lastSentTtl);

		// Release the lock
		Cache::forget($lockKey);

		Log::debug("🟢🔒 TS-RELEASED: $key");
	}
}
