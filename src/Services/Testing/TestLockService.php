<?php

namespace Newms87\Danx\Services\Testing;

use Redis;
use RedisException;
use RuntimeException;

/**
 * Manages exclusive test locks to prevent concurrent test suite execution.
 *
 * Uses Redis for distributed lock coordination with FIFO queue ordering
 * so multiple waiters are served in arrival order.
 *
 * Key features:
 * - 60-second TTL auto-expires stale locks (no manual cleanup needed)
 * - Auto-retry with 100ms intervals (max 600 attempts = 60 seconds)
 * - FIFO queue ensures fair ordering when multiple processes wait
 * - Heartbeat refresh keeps locks alive during long test suites
 *
 * Usage in TestCase:
 * ```php
 * use Newms87\Danx\Traits\UsesTestLock;
 *
 * abstract class TestCase extends BaseTestCase
 * {
 *     use UsesTestLock;
 * }
 * ```
 */
class TestLockService
{
    private const int LOCK_TTL_SECONDS = 60;

    private const int RETRY_INTERVAL_MS = 100;

    private const int MAX_ATTEMPTS = 600;

    private const int LOG_INTERVAL_SECONDS = 5;

    private const string LOCK_KEY = 'test-lock';

    private const string QUEUE_KEY = 'test-lock-queue';

    private ?string $ownerId = null;

    private ?Redis $redis = null;

    private string $keyPrefix;

    public function __construct(?string $keyPrefix = null)
    {
        $this->keyPrefix = $keyPrefix ?? $this->detectKeyPrefix();
    }

    /**
     * Acquires an exclusive lock for running tests.
     *
     * @throws RuntimeException If Redis unavailable or lock cannot be acquired
     */
    public function acquireLock(): void
    {
        $this->ownerId = $this->generateOwnerId();
        $this->redis   = $this->connectRedis();

        $this->joinQueue();
        $this->registerShutdownHandler();
        $this->waitForLock();
    }

    /**
     * Releases the test lock and removes from queue.
     */
    public function releaseLock(): void
    {
        if ($this->ownerId === null) {
            return;
        }

        $this->leaveQueue();
        $this->deleteLock();
        $this->ownerId = null;
    }

    /**
     * Refreshes the lock TTL to prevent expiration during long test suites.
     */
    public function refreshHeartbeat(): void
    {
        if ($this->ownerId === null || $this->redis === null) {
            return;
        }

        $this->safeRedis(function () {
            $currentOwner = $this->redis->get($this->key(self::LOCK_KEY));
            if ($currentOwner === $this->ownerId) {
                $this->redis->expire($this->key(self::LOCK_KEY), self::LOCK_TTL_SECONDS);
            }
        });
    }

    public function getOwnerId(): ?string
    {
        return $this->ownerId;
    }

    /**
     * Detects the key prefix from the application directory name.
     */
    private function detectKeyPrefix(): string
    {
        return basename(getcwd()) . ':';
    }

    /**
     * Returns the full Redis key with prefix.
     */
    private function key(string $name): string
    {
        return $this->keyPrefix . $name;
    }

    private function waitForLock(): void
    {
        $startTime   = time();
        $lastLogTime = 0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->cleanStaleQueueEntries();

            if ($this->isFirstInQueue() && $this->tryAcquireLock()) {
                $elapsed = time() - $startTime;
                if ($elapsed > 0) {
                    $this->log("Lock acquired (took {$elapsed}s)");
                }

                return;
            }

            $now = time();
            if ($now - $lastLogTime >= self::LOG_INTERVAL_SECONDS) {
                $elapsed  = $now - $startTime;
                $position = $this->getQueuePosition();
                $msg      = "Waiting for test lock... ({$elapsed}s)";
                if ($position > 0) {
                    $msg .= " - queued behind {$position}";
                }
                $this->log($msg);
                $lastLogTime = $now;
            }

            usleep(self::RETRY_INTERVAL_MS * 1000);
        }

        $this->leaveQueue();
        throw new RuntimeException('Failed to acquire test lock after 60 seconds.');
    }

    private function generateOwnerId(): string
    {
        return getmypid() . ':' . bin2hex(random_bytes(8));
    }

    private function connectRedis(): Redis
    {
        foreach (['redis', '127.0.0.1'] as $host) {
            try {
                $redis = new Redis;
                if (@$redis->connect($host, 6379, 1.0)) {
                    $redis->ping();

                    return $redis;
                }
            } catch (RedisException) {
                // Try next host
            }
        }

        throw new RuntimeException('Redis is required for test locking but unavailable.');
    }

    private function joinQueue(): void
    {
        $this->safeRedis(fn() => $this->redis->zAdd($this->key(self::QUEUE_KEY), microtime(true), $this->ownerId));
    }

    private function leaveQueue(): void
    {
        $this->safeRedis(fn() => $this->redis->zRem($this->key(self::QUEUE_KEY), $this->ownerId));
    }

    private function isFirstInQueue(): bool
    {
        return $this->safeRedis(function () {
            $first = $this->redis->zRange($this->key(self::QUEUE_KEY), 0, 0);

            return ($first[0] ?? null) === $this->ownerId;
        }, true);
    }

    private function getQueuePosition(): int
    {
        return $this->safeRedis(function () {
            $rank = $this->redis->zRank($this->key(self::QUEUE_KEY), $this->ownerId);

            return $rank !== false ? (int)$rank : 0;
        }, 0);
    }

    private function cleanStaleQueueEntries(): void
    {
        $this->safeRedis(function () {
            $members = $this->redis->zRange($this->key(self::QUEUE_KEY), 0, -1);
            foreach ($members as $member) {
                $pid = (int)explode(':', $member)[0];
                if ($pid > 0 && !posix_kill($pid, 0)) {
                    $this->redis->zRem($this->key(self::QUEUE_KEY), $member);
                }
            }
        });
    }

    private function tryAcquireLock(): bool
    {
        return $this->safeRedis(function () {
            return $this->redis->set(
                $this->key(self::LOCK_KEY),
                $this->ownerId,
                ['NX', 'EX' => self::LOCK_TTL_SECONDS]
            ) === true;
        }, false);
    }

    private function deleteLock(): void
    {
        $this->safeRedis(function () {
            $script = <<<'LUA'
                if redis.call("get", KEYS[1]) == ARGV[1] then
                    return redis.call("del", KEYS[1])
                end
                return 0
                LUA;
            $this->redis->eval($script, [$this->key(self::LOCK_KEY), $this->ownerId], 1);
        });
    }

    private function registerShutdownHandler(): void
    {
        register_shutdown_function(fn() => $this->leaveQueue());
    }

    private function log(string $message): void
    {
        fwrite(STDERR, "[TestLock] {$message}\n");
    }

    /**
     * Wraps Redis calls with exception handling.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T|null  $default
     * @return T|null
     */
    private function safeRedis(callable $callback, mixed $default = null): mixed
    {
        if ($this->redis === null) {
            return $default;
        }

        try {
            return $callback();
        } catch (RedisException) {
            return $default;
        }
    }
}
