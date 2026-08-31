<?php

namespace Newms87\Danx\Traits;

use Newms87\Danx\Services\Testing\TestLockService;

/**
 * Provides distributed test locking for TestCase classes.
 *
 * Use this trait in your application's base TestCase to prevent concurrent test
 * suite execution when using RefreshDatabase. The lock is acquired before the
 * first test class runs and held for the LIFETIME OF THE PROCESS.
 *
 * Features:
 * - Redis-based distributed locking (requires Redis)
 * - FIFO queue for fair ordering when multiple suites wait
 * - Heartbeat refresh to keep lock alive during long test runs
 * - Automatic release on process exit
 *
 * ## The lock is per-process, NOT per-class — this is load-bearing
 *
 * It used to acquire in `setUpBeforeClass()` and release in `tearDownAfterClass()`,
 * which dropped and re-took the lock between EVERY test class. A second suite
 * waiting in the queue wins one of those gaps and starts migrating the shared
 * database out from under the first suite, mid-run.
 *
 * The symptom is not a clean "could not acquire lock". It is a scatter of unrelated
 * failures — missing rows, unexpected truncation, foreign-key violations — in
 * whichever suite lost the race, which reads as flakiness in application code rather
 * than as contention. A gpt-manager run overlapping another produced 58 such
 * failures; 40 of them evaporated when the same tests were re-run in isolation.
 *
 * Do not "simplify" this back into a symmetric setUpBeforeClass/tearDownAfterClass
 * pair. Release happens in a shutdown handler instead, so it survives a fatal error
 * or an interrupted run — which `tearDownAfterClass()` would not have.
 *
 * Usage:
 * ```php
 * abstract class TestCase extends BaseTestCase
 * {
 *     use UsesTestLock;
 *
 *     public function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->refreshTestLockHeartbeat();
 *     }
 * }
 * ```
 */
trait UsesTestLock
{
    private static ?TestLockService $testLockService = null;

    /**
     * True once the first test class in this process has taken the lock.
     *
     * Tracked separately from `$testLockService` being non-null: the service is
     * nulled on release, and without this flag a release would be indistinguishable
     * from "never acquired" and would silently re-acquire late in the run.
     */
    private static bool $testLockAcquired = false;

    /**
     * Acquire the test lock once, before the first test class in this process runs.
     *
     * Every subsequent class is a no-op — the lock is already held and must not be
     * dropped between classes. See the class docblock.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$testLockAcquired) {
            return;
        }

        self::$testLockAcquired = true;
        self::$testLockService  = new TestLockService;
        self::$testLockService->acquireLock();

        register_shutdown_function(static fn() => self::releaseTestLock());
    }

    /**
     * Deliberately does NOT release the lock — see the class docblock.
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
    }

    /**
     * Release the process-wide lock. Idempotent; runs from the shutdown handler.
     */
    public static function releaseTestLock(): void
    {
        self::$testLockService?->releaseLock();
        self::$testLockService = null;
    }

    /**
     * Refresh the lock heartbeat before each test.
     *
     * Call this from your setUp() method to keep the lock alive during long test suites.
     */
    protected function refreshTestLockHeartbeat(): void
    {
        self::$testLockService?->refreshHeartbeat();
    }

    /**
     * Get the test lock service instance (for testing purposes).
     */
    protected static function getTestLockService(): ?TestLockService
    {
        return self::$testLockService;
    }
}
