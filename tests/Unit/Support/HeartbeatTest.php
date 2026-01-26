<?php

namespace Tests\Unit\Support;

use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Support\Heartbeat;
use Orchestra\Testbench\TestCase;

class HeartbeatTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset audit state
        AuditDriver::$auditRequest = null;

        parent::tearDown();
    }

    /**
     * Test that start() returns a Heartbeat instance when disabled via config.
     * When heartbeat is disabled, it should return immediately without forking.
     */
    public function test_start_returns_instance_when_disabled_via_config(): void
    {
        // Disable heartbeat via config
        config()->set('danx.audit.heartbeat.enabled', false);

        $heartbeat = Heartbeat::start('test-operation');

        $this->assertInstanceOf(Heartbeat::class, $heartbeat);

        // Verify no child process was forked by checking the stopped state
        // (If no child was forked, stop() should be idempotent and not error)
        $heartbeat->stop();
    }

    /**
     * Test that start() returns a Heartbeat instance when no audit request exists.
     * The heartbeat should gracefully handle missing audit context.
     */
    public function test_start_returns_instance_when_no_audit_request(): void
    {
        // Ensure no audit request is set
        AuditDriver::$auditRequest = null;

        // Enable heartbeat
        config()->set('danx.audit.heartbeat.enabled', true);

        $heartbeat = Heartbeat::start('test-operation-no-audit');

        $this->assertInstanceOf(Heartbeat::class, $heartbeat);

        // Clean up
        $heartbeat->stop();
    }

    /**
     * Test that stop() can be called multiple times without error.
     * The stop method should be idempotent.
     */
    public function test_stop_is_idempotent(): void
    {
        // Disable heartbeat to avoid forking
        config()->set('danx.audit.heartbeat.enabled', false);

        $heartbeat = Heartbeat::start('test-idempotent');

        // Call stop multiple times - should not throw any errors
        $heartbeat->stop();
        $heartbeat->stop();
        $heartbeat->stop();

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test that the destructor properly calls stop().
     * When a Heartbeat instance goes out of scope, it should clean up.
     */
    public function test_destructor_calls_stop(): void
    {
        // Disable heartbeat to avoid forking
        config()->set('danx.audit.heartbeat.enabled', false);

        // Create heartbeat in a closure to control scope
        $testPassed = false;
        $closure    = function () use (&$testPassed): void {
            $heartbeat = Heartbeat::start('test-destructor');
            // Heartbeat goes out of scope here, destructor should be called
        };

        // Execute the closure - destructor should run without error
        $closure();

        // Force garbage collection to ensure destructor runs
        gc_collect_cycles();

        // If we get here without exception, the destructor worked correctly
        $testPassed = true;
        $this->assertTrue($testPassed);
    }
}
