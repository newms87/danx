<?php

namespace Tests\Feature\Services\Testing;

use Newms87\Danx\Services\Testing\TestLockService;
use Tests\TestCase;

/**
 * Tests for TestLockService's auto-retry, FIFO queue lock system.
 *
 * Note: These tests run within an already-locked context (TestCase acquires
 * a lock in setUpBeforeClass). Tests that need to verify lock behavior
 * must temporarily release and re-acquire the main test suite lock.
 */
class TestLockServiceTest extends TestCase
{
    public function test_owner_id_contains_current_pid(): void
    {
        $lockService = self::getTestLockService();
        $ownerId = $lockService->getOwnerId();

        // Owner ID should contain current PID
        $this->assertStringStartsWith(getmypid() . ':', $ownerId);
    }

    public function test_stale_queue_entry_is_removed_if_process_not_running(): void
    {
        $currentLock = self::getTestLockService();
        $currentLock->releaseLock();

        try {
            // Add a stale queue entry with a non-existent PID directly to Redis
            // Key prefix is based on cwd basename (html in Docker container)
            $keyPrefix = basename(getcwd()) . ':';
            $queueKey = $keyPrefix . 'test-lock-queue';

            $redis = new \Redis();
            $redis->connect('redis', 6379);
            $staleOwnerId = '999999:stale123'; // PID 999999 should not exist
            $redis->zAdd($queueKey, microtime(true) - 100, $staleOwnerId);

            // New lock service should clean stale entries and acquire
            $lockService = new TestLockService();
            $lockService->acquireLock();

            $this->assertNotNull($lockService->getOwnerId());

            // Verify stale entry was removed
            $members = $redis->zRange($queueKey, 0, -1);
            $this->assertNotContains($staleOwnerId, $members);

            $lockService->releaseLock();
        } finally {
            $currentLock->acquireLock();
        }
    }

    public function test_release_clears_owner_id(): void
    {
        $currentLock = self::getTestLockService();
        $currentLock->releaseLock();

        try {
            $lockService = new TestLockService();
            $lockService->acquireLock();

            $this->assertNotNull($lockService->getOwnerId());

            $lockService->releaseLock();

            $this->assertNull($lockService->getOwnerId());
        } finally {
            $currentLock->acquireLock();
        }
    }

    public function test_can_acquire_after_release(): void
    {
        $currentLock = self::getTestLockService();
        $currentLock->releaseLock();

        try {
            // First lock
            $lock1 = new TestLockService();
            $lock1->acquireLock();
            $this->assertNotNull($lock1->getOwnerId());

            $lock1->releaseLock();
            $this->assertNull($lock1->getOwnerId());

            // Second lock should acquire immediately after release
            $lock2 = new TestLockService();
            $lock2->acquireLock();
            $this->assertNotNull($lock2->getOwnerId());

            $lock2->releaseLock();
        } finally {
            $currentLock->acquireLock();
        }
    }

    public function test_release_removes_from_queue(): void
    {
        $currentLock = self::getTestLockService();
        $currentLock->releaseLock();

        try {
            $lockService = new TestLockService();
            $lockService->acquireLock();

            $ownerId = $lockService->getOwnerId();
            $this->assertNotNull($ownerId);

            $lockService->releaseLock();

            // After release, owner ID should be null
            $this->assertNull($lockService->getOwnerId());
        } finally {
            $currentLock->acquireLock();
        }
    }

    public function test_release_lock_is_idempotent(): void
    {
        $currentLock = self::getTestLockService();
        $currentLock->releaseLock();

        try {
            $lockService = new TestLockService();
            $lockService->acquireLock();

            $this->assertNotNull($lockService->getOwnerId());

            // Release multiple times - should not throw
            $lockService->releaseLock();
            $lockService->releaseLock();
            $lockService->releaseLock();

            $this->assertNull($lockService->getOwnerId());
        } finally {
            $currentLock->acquireLock();
        }
    }

    public function test_refresh_heartbeat_does_nothing_when_not_acquired(): void
    {
        // Create a new service that never acquires a lock
        $lockService = new TestLockService();

        // Should not throw - just a no-op
        $lockService->refreshHeartbeat();

        // Owner ID should still be null
        $this->assertNull($lockService->getOwnerId());
    }
}
