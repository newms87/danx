<?php

namespace Newms87\Danx\Helpers;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 *
 * OWNERSHIP. Every lock this class acquires is a real Laravel cache lock with an owner
 * token — either one Laravel mints itself (the common, same-process case) or one this
 * caller supplies explicitly (the cross-process handoff case, see below). `release()`
 * and `extend()` always check that token before touching the lock: they NEVER delete or
 * extend a lock this call didn't actually establish ownership of. A hold whose TTL lapsed
 * cannot, on its way out, delete or extend the lock a later writer has since taken —
 * that is the exact bug this class replaced (`forceRelease()`, an unconditional,
 * owner-blind delete/reacquire, used to sit behind both `release()` and `extend()`).
 *
 * SAME-PROCESS USE (the common case) — unchanged call shape:
 *   LockHelper::acquire($key); ... ; LockHelper::release($key);
 *   LockHelper::acquire($key); ... ; LockHelper::extend($key);
 * `$acquiredLocks` remembers the real, owned `Lock` object (keyed by resolved key AND the
 * pid that acquired it), so `release()`/`extend()` always act on this process's own,
 * currently-valid lock — never a fresh, ownerless handle. The pid check also means a
 * `ProcessFork` child, which inherits its parent's memory but never its lock, correctly
 * takes its own lock rather than mistaking the parent's hold for its own.
 *
 * CROSS-PROCESS / CROSS-MACHINE HANDOFF — a real, legitimate pattern in this app: a
 * dispatcher acquires a lock, then hands the *work* off to a queued job that may run on
 * a different machine; that job is the one that releases (or refreshes) the lock once
 * it has safely started, so a request that arrives before the job starts is deduped and
 * a request that arrives after it starts knows to queue fresh work rather than assume
 * the in-flight run will see it. Laravel's lock contract supports this by letting the
 * caller choose an explicit owner token at acquire time and restore a handle to that
 * SAME owner from anywhere:
 *   $owner = LockHelper::acquireForHandoff($key, $waitTime, $ttl);   // acquiring process
 *   // ... $owner is carried along (e.g. persisted on the job's own payload/cache row) ...
 *   LockHelper::releaseByOwner($key, $owner);                       // releasing process
 *   LockHelper::extendByOwner($key, $owner, $ttl);                  // refreshing process
 * These never touch `$acquiredLocks` — the acquiring process is not the one that will
 * release it, so there is nothing to remember in-process.
 *
 * There is deliberately no "force clear regardless of owner" method. Every real caller in
 * this codebase either releases what it acquired (same process) or is handed the owner
 * token explicitly (cross-process) — nothing needs, and nothing should get, unconditional
 * clearing of a lock it never actually held.
 */
class LockHelper
{
	// Locks are only important to lock out other requests from modifying the same resource
	// We can keep track of the locks we have acquired in memory
	// This way we don't block ourselves if we try to acquire the same lock twice
	/** @var array<string, array{lock: Lock, pid: int}> */
	static array $acquiredLocks = [];

	// Only lock a resource for 30 seconds by default
	const TTL = 30;

	// Only make this many attempts to acquire the resource lock before throwing an error
	const WAIT_TIME = 31;

	/**
	 * Extend a lock only while it is still this owner's; take it back over if it lapsed and
	 * nobody else has taken it since (nobody wrote to the resource in between, so the hold
	 * may continue); otherwise refuse. Atomic on Redis (every deployed environment) via this
	 * script; see {@see renewByOwner()} for the non-Redis fallback used by tests.
	 *
	 * KEYS[1] - the lock's name   ARGV[1] - the owner token   ARGV[2] - the TTL in milliseconds
	 */
	protected const string RENEW_SCRIPT = <<<'LUA'
local owner = redis.call("get", KEYS[1])
if owner == ARGV[1] then
    return redis.call("pexpire", KEYS[1], ARGV[2])
elseif not owner then
    return redis.call("set", KEYS[1], ARGV[1], "PX", ARGV[2], "NX") and 1 or 0
end
return 0
LUA;

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

		if (static::heldByThisProcess($key)) {
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

			static::$acquiredLocks[$key] = ['lock' => $lock, 'pid' => getmypid()];
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

		if (static::heldByThisProcess($key)) {
			Log::debug("🔴🔒 LOCK ALREADY ACQUIRED: $key");

			return true;
		}

		$lock       = Cache::lock($key, $ttl);
		$isAcquired = $lock->get();

		if ($isAcquired) {
			static::$acquiredLocks[$key] = ['lock' => $lock, 'pid' => getmypid()];
			Log::debug("🔴🔒 ACQUIRED: $key");
		}

		return $isAcquired;
	}

	/**
	 * Get a lock on a key, always checking the real distributed backend --
	 * NEVER short-circuited by the in-memory $acquiredLocks reentrancy cache.
	 *
	 * get()'s in-memory shortcut exists so a single call stack that legitimately
	 * re-enters the same lock doesn't block itself. But any caller that acquires
	 * via get() and (correctly, per a TTL-based "auto-expires, never explicitly
	 * released" design) never calls release() will see get() return true for
	 * that key FOREVER, for the remaining lifetime of the PHP process --
	 * regardless of whether the real distributed lock has actually expired.
	 * Long-lived queue workers (Horizon with maxJobs=0/maxTime=0) process
	 * thousands of unrelated jobs per process, so this silently defeats mutual
	 * exclusion between independent, later job invocations that happen to land
	 * on the same worker process.
	 *
	 * SG-193: this is exactly what let a second, independent transcode-recovery
	 * dispatch slip past TranscodeFileService's supposedly TTL-bounded lock and
	 * fire a duplicate render for pages already in flight from an earlier
	 * dispatch on the same worker process, well within the lock's own TTL.
	 *
	 * Use this instead of get() for any lock meant to coordinate across
	 * independent async dispatches (separate jobs/requests), rather than
	 * same-call-stack reentrancy, where the lock is intentionally never
	 * explicitly released.
	 */
	public static function getDistributed($key, $ttl = LockHelper::TTL): bool
	{
		$key = self::resolveKey($key);

		$isAcquired = Cache::lock($key, $ttl)->get();

		if ($isAcquired) {
			Log::debug("🔴🔒 ACQUIRED (distributed): $key");
		}

		return $isAcquired;
	}

	/**
	 * Acquire a lock for handoff to a DIFFERENT process/machine, which will be the one to
	 * release or extend it (see the class docblock's "CROSS-PROCESS / CROSS-MACHINE
	 * HANDOFF" section). Mints an explicit owner token and returns it — the caller is
	 * responsible for carrying that token to wherever `releaseByOwner()`/`extendByOwner()`
	 * will run. Deliberately does NOT populate `$acquiredLocks`: this process is not the
	 * one that will release it, so there is nothing to remember here.
	 *
	 * @throws LockException when the lock did not come free within $waitTime
	 */
	public static function acquireForHandoff(Model|string $key, int $waitTime = LockHelper::WAIT_TIME, int $ttl = LockHelper::TTL): string
	{
		$model = $key instanceof Model ? $key : null;
		$key   = self::resolveKey($key);
		$owner = (string)Str::uuid();

		$lock = Cache::lock($key, $ttl, $owner);

		try {
			$firstLock = $lock->get();

			if (!$firstLock) {
				Log::debug("🟡🔒 WAIT: $key ($waitTime s)");
				$blockAt = microtime(true);
				$lock->block($waitTime);

				if (microtime(true) - $blockAt >= $waitTime) {
					Log::error("🔴🔒 TIMEOUT: $key");
				}
			}

			Log::debug("🔴🔒 ACQUIRED (for handoff): $key");
			$model?->refresh();
		} catch(Throwable $exception) {
			throw new LockException($key, $waitTime, $exception);
		}

		return $owner;
	}

	/**
	 * Re-set the TTL on an already-held lock without re-blocking, for the SAME process
	 * that acquired it. Atomic and owner-checked (see {@see renewByOwner()}) — never the
	 * old force-release-then-reacquire, which carried a real race window between the two
	 * steps.
	 *
	 * @throws LockException when this process does not hold $key, or the lock is no longer its own
	 */
	public static function extend(Model|string $key, int $ttl = LockHelper::TTL): bool
	{
		$key   = self::resolveKey($key);
		$entry = static::heldByThisProcess($key) ? static::$acquiredLocks[$key] : null;

		if (!$entry) {
			Log::error("🔴🔒 EXTEND-NOT-HELD: $key — this process never acquired it; use extendByOwner() for a cross-process handoff");
			throw new LockException($key, 0);
		}

		if (!static::renewByOwner($key, $entry['lock']->owner(), $ttl)) {
			Log::error("🔴🔒 EXTEND-FAILED: $key — the lock is no longer this process's; another writer took it after the TTL lapsed");
			throw new LockException($key, 0);
		}

		Log::debug("🔵🔒 EXTENDED: $key (ttl=$ttl)");

		return true;
	}

	/**
	 * Release a lock this process acquired via {@see acquire()}/{@see get()}/
	 * {@see getDistributed()}. Owner-checked: releases the real lock this process holds,
	 * never a fresh, ownerless handle on the same key. A key this process does not
	 * currently hold is left untouched — there is nothing safe to release.
	 */
	public static function release($key): void
	{
		$key   = self::resolveKey($key);
		$entry = static::heldByThisProcess($key) ? static::$acquiredLocks[$key] : null;

		unset(static::$acquiredLocks[$key]);

		if (!$entry) {
			Log::error("🔴🔒 RELEASE-NOT-HELD: $key — this process never acquired it; nothing was released. Use releaseByOwner() for a cross-process handoff");

			return;
		}

		if ($entry['lock']->release()) {
			Log::debug("🟢🔒 RELEASED: $key");
		} else {
			Log::error("🔴🔒 LOST: $key — the lock was no longer this process's own by the time it released; left untouched");
		}
	}

	/**
	 * Release a lock acquired elsewhere via {@see acquireForHandoff()}, using the owner
	 * token that acquisition returned. Owner-checked via {@see \Illuminate\Cache\Repository::restoreLock()}
	 * — succeeds only if $owner still matches whoever currently holds $key.
	 */
	public static function releaseByOwner(string $key, string $owner): bool
	{
		$key      = self::resolveKey($key);
		$released = Cache::restoreLock($key, $owner)->release();

		if ($released) {
			Log::debug("🟢🔒 RELEASED (by owner): $key");
		} else {
			Log::error("🔴🔒 RELEASE-BY-OWNER-FAILED: $key — not held by the given owner (expired and re-acquired by someone else, or never held)");
		}

		return $released;
	}

	/**
	 * Extend a lock acquired elsewhere via {@see acquireForHandoff()}, using the owner
	 * token that acquisition returned. Atomic and owner-checked — see {@see renewByOwner()}.
	 *
	 * @throws LockException when $key is no longer held by $owner
	 */
	public static function extendByOwner(string $key, string $owner, int $ttl = LockHelper::TTL): bool
	{
		$key = self::resolveKey($key);

		if (!static::renewByOwner($key, $owner, $ttl)) {
			Log::error("🔴🔒 EXTEND-BY-OWNER-FAILED: $key — not held by the given owner");
			throw new LockException($key, 0);
		}

		Log::debug("🔵🔒 EXTENDED (by owner): $key (ttl=$ttl)");

		return true;
	}

	/**
	 * Extend $key by one TTL if it is still $owner's (or lapsed with nobody else taking it).
	 * The single, shared, atomic renewal primitive behind {@see extend()}/{@see extendByOwner()}
	 * — also used directly by {@see \App\Services\TeamObject\TeamObjectScopeLock} so that class
	 * does not carry its own copy of this logic.
	 *
	 * Atomic on Redis (the store every deployed environment uses) through {@see RENEW_SCRIPT}.
	 * The array store (tests) is one process, so a read-then-write is atomic there too. Any
	 * other cache driver has no atomic primitive to renew by; it can only be asked whether the
	 * lock is still this owner's, which is the best a checkpoint can know on such a store.
	 */
	public static function renewByOwner(string $key, string $owner, int $ttl): bool
	{
		$store = Cache::getStore();

		if ($store instanceof RedisStore) {
			$connection = $store->lockConnection();
			$packedOwner = $connection instanceof PhpRedisConnection ? $connection->pack([$owner])[0] : $owner;

			return (bool)$connection->eval(self::RENEW_SCRIPT, 1, $store->getPrefix() . $key, $packedOwner, $ttl * 1000);
		}

		if ($store instanceof ArrayStore) {
			// Another owner in the entry means another writer took the lock after it lapsed.
			if (($store->locks[$key]['owner'] ?? $owner) !== $owner) {
				return false;
			}

			$store->locks[$key] = ['owner' => $owner, 'expiresAt' => Carbon::now()->addSeconds($ttl)];

			return true;
		}

		return Cache::restoreLock($key, $owner)->isOwnedByCurrentProcess();
	}

	/**
	 * @param $key
	 */
	public static function resolveKey($key)
	{
		if ($key instanceof Model) {
			return $key::class . ':' . $key->getKey();
		} else {
			return $key;
		}
	}

	/** Whether THIS process (by pid) holds $key via acquire()/get()/getDistributed()'s in-memory registry. */
	protected static function heldByThisProcess(string $key): bool
	{
		return (static::$acquiredLocks[$key]['pid'] ?? null) === getmypid();
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
	 * Check if a timestamp is stale (older than the last sent timestamp).
	 * This is a quick check without acquiring any locks.
	 *
	 * @param  Model|string  $key        The lock key
	 * @param  string        $timestamp  The timestamp to check
	 * @return bool True if the timestamp is stale
	 */
	public static function isStaleTimestamp(Model|string $key, string $timestamp): bool
	{
		$key = self::resolveKey($key);
		$lastSentKey = "ts-last-sent:$key";

		$lastSentAt = Cache::get($lastSentKey);

		return $lastSentAt && $timestamp < $lastSentAt;
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
