<?php

namespace Newms87\Danx\Traits;

use Newms87\Danx\Services\Testing\TestLockService;

/**
 * Provides distributed test locking for TestCase classes.
 *
 * Use this trait in your application's TestCase to prevent concurrent
 * test suite execution when using RefreshDatabase. The lock is acquired
 * before any test class runs and released after all tests complete.
 *
 * Features:
 * - Redis-based distributed locking (requires Redis)
 * - FIFO queue for fair ordering when multiple suites wait
 * - Heartbeat refresh to keep lock alive during long test runs
 * - Automatic cleanup on process exit
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
 *         // Your setup code...
 *     }
 * }
 * ```
 */
trait UsesTestLock
{
    private static ?TestLockService $testLockService = null;

    /**
     * Acquire the test lock before any test class runs.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$testLockService = new TestLockService;
        self::$testLockService->acquireLock();
    }

    /**
     * Release the test lock after all tests in the class complete.
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$testLockService !== null) {
            self::$testLockService->releaseLock();
            self::$testLockService = null;
        }

        parent::tearDownAfterClass();
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
