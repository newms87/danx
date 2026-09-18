<?php

namespace Tests\Unit\Traits;

use Newms87\Danx\Services\Testing\TestLockService;
use Newms87\Danx\Traits\UsesTestLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SG-623: a lock acquisition that throws must not leave the lock recorded as held.
 *
 * Before the fix, UsesTestLock marked the lock as held before acquireLock() ran. When
 * acquireLock() timed out, every later test class in the process skipped locking and ran
 * with no lock at all.
 */
class UsesTestLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        UsesTestLockProbe::reset();
    }

    public function test_a_failed_acquisition_is_not_recorded_and_the_next_class_tries_again(): void
    {
        UsesTestLockProbe::$failuresBeforeSuccess = 1;

        try {
            UsesTestLockProbe::setUpBeforeClass();
            $this->fail('The first class must fail loudly when the lock cannot be acquired');
        } catch (RuntimeException $e) {
            $this->assertSame('lock wait budget exceeded', $e->getMessage());
        }

        $this->assertNull(UsesTestLockProbe::heldService(), 'A failed acquisition must not be recorded as held');

        UsesTestLockProbe::setUpBeforeClass();

        $this->assertSame(2, UsesTestLockProbe::$acquireAttempts, 'The next class must try to acquire the lock again');
        $this->assertNotNull(UsesTestLockProbe::heldService(), 'The retry succeeded, so the lock is now held');
    }

    public function test_once_held_later_classes_do_not_acquire_again(): void
    {
        UsesTestLockProbe::setUpBeforeClass();
        UsesTestLockProbe::setUpBeforeClass();

        $this->assertSame(1, UsesTestLockProbe::$acquireAttempts, 'The lock is held for the whole process, so it is acquired once');
    }
}

/**
 * Stands in for PHPUnit's TestCase as the parent the trait calls into.
 */
abstract class UsesTestLockProbeParent
{
    public static function setUpBeforeClass(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
    }
}

/**
 * A class using the trait whose lock service fails a set number of times before it succeeds.
 */
class UsesTestLockProbe extends UsesTestLockProbeParent
{
    use UsesTestLock;

    public static int $failuresBeforeSuccess = 0;

    public static int $acquireAttempts = 0;

    public static function reset(): void
    {
        self::$failuresBeforeSuccess = 0;
        self::$acquireAttempts       = 0;
        self::$testLockService       = null;
        self::$testLockAcquired      = false;
    }

    public static function heldService(): ?TestLockService
    {
        return self::getTestLockService();
    }

    protected static function makeTestLockService(): TestLockService
    {
        return new class extends TestLockService {
            public function acquireLock(): void
            {
                UsesTestLockProbe::$acquireAttempts++;

                if (UsesTestLockProbe::$acquireAttempts <= UsesTestLockProbe::$failuresBeforeSuccess) {
                    throw new RuntimeException('lock wait budget exceeded');
                }
            }

            public function releaseLock(): void
            {
            }
        };
    }
}
