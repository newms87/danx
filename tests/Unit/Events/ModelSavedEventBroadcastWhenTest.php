<?php

namespace Tests\Unit\Events;

use Illuminate\Support\Facades\Cache;
use Newms87\Danx\Events\AuditRequestUpdatedEvent;
use Newms87\Danx\Models\Audit\AuditRequest;
use Orchestra\Testbench\TestCase;

/**
 * Tests the broadcastWhen() subscriber gate on ModelSavedEvent.
 *
 * Laravel calls broadcastWhen() synchronously before enqueuing a broadcast job
 * (Illuminate\Events\Dispatcher::shouldBroadcast()). Returning false keeps the job
 * from ever being queued, so high-frequency model mutations with no listening client
 * (e.g. AuditRequest log/heartbeat/counter writes) produce zero broadcast queue jobs.
 *
 * Exercised through the concrete AuditRequestUpdatedEvent subclass. No persistence or
 * Redis required — the gate resolves subscribers purely from the cache-backed
 * subscription registry.
 */
class ModelSavedEventBroadcastWhenTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // Subscriptions resolve from the default cache store; use an in-memory store so
        // the gate is exercised without a cache table or Redis.
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeEvent(): AuditRequestUpdatedEvent
    {
        $auditRequest = new AuditRequest(['team_id' => 1, 'url' => '/test']);
        $auditRequest->id = 1;

        return new AuditRequestUpdatedEvent($auditRequest, 'updated');
    }

    public function test_broadcast_when_returns_false_with_no_subscribers(): void
    {
        $this->assertFalse($this->makeEvent()->broadcastWhen());
    }

    public function test_broadcast_when_returns_true_with_a_channel_wide_subscriber(): void
    {
        // Same cache shape the subscription system writes when a client subscribes
        // to all AuditRequests for the team.
        Cache::put('subscribe:AuditRequest:1:all', [42]);

        $this->assertTrue($this->makeEvent()->broadcastWhen());
    }

    public function test_broadcast_when_returns_true_with_a_model_specific_subscriber(): void
    {
        Cache::put('subscribe:AuditRequest:1:id:1', [42]);

        $this->assertTrue($this->makeEvent()->broadcastWhen());
    }
}
