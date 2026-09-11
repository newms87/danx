<?php

namespace Tests\Unit\Events;

use Illuminate\Support\Facades\Cache;
use Newms87\Danx\Events\AuditRequestUpdatedEvent;
use Newms87\Danx\Events\ModelSavedEvent;
use Newms87\Danx\Models\Audit\AuditRequest;
use Orchestra\Testbench\TestCase;

/**
 * A broadcast names the subscription entries it satisfied (`__subscriptions`).
 *
 * WHY: the channel is one per resource type per team, so every client on the team receives
 * every event the gate lets through, and the gate lets one through when ANY stored
 * subscription matches. Without a name on the event a client cannot tell "this matched my
 * filter" from "this matched a teammate's wider list" — observed as a schema-scoped queue
 * counting a demand of another schema while a teammate held an unscoped subscription.
 *
 * The names are the subscription cache keys themselves — the exact strings the subscribe
 * side wrote and the gate read — so both ends compare values neither had to derive.
 *
 * Exercised through the concrete AuditRequestUpdatedEvent with an in-memory cache and no
 * database, so every case uses a `deleted` event: it is the one path that neither refreshes
 * the model nor queries its table — a filter entry is matched in memory and the payload is
 * built from attributes. Channel-wide and model-specific entries resolve from the cache alone
 * whatever the event. The query-backed filter path is covered end to end in gpt-manager's
 * WorkflowInputsListSchemaScopeTest, with a real dot-notation filter.
 */
class ModelSavedEventSubscriptionAttributionTest extends TestCase
{
    private const string ALL = 'subscribe:AuditRequest:1:all';

    private const string ONE = 'subscribe:AuditRequest:1:id:1';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_the_payload_names_every_entry_the_model_satisfied_and_no_other(): void
    {
        Cache::put(self::ALL, [42]);
        Cache::put(self::ONE, [43]);
        Cache::put('subscribe:AuditRequest:1:id:2', [44]);

        $this->assertSame([self::ALL, self::ONE], $this->subscriptionsNamedBy($this->makeEvent(ModelSavedEvent::EVENT_DELETED)));
    }

    public function test_an_entry_with_no_subscribers_left_is_not_named(): void
    {
        Cache::put(self::ALL, []);
        Cache::put(self::ONE, [43]);

        $this->assertSame([self::ONE], $this->subscriptionsNamedBy($this->makeEvent(ModelSavedEvent::EVENT_DELETED)));
    }

    public function test_a_filter_entry_is_named_only_when_its_filter_matches(): void
    {
        $this->putFilter('matches', ['url' => '/test'], [42]);
        $this->putFilter('misses', ['url' => '/elsewhere'], [43]);

        $this->assertSame(
            ['subscribe:AuditRequest:1:filter:matches'],
            $this->subscriptionsNamedBy($this->makeEvent(ModelSavedEvent::EVENT_DELETED)),
        );
    }

    public function test_a_teammates_wider_entry_is_named_without_naming_a_narrower_one_that_missed(): void
    {
        // The observed shape: one member lists everything, another a filtered queue the model
        // is not in. The event must reach the channel (for the first) and say so.
        Cache::put(self::ALL, [2]);
        $this->putFilter('scoped', ['url' => '/elsewhere'], [5]);

        $event = $this->makeEvent(ModelSavedEvent::EVENT_DELETED);

        $this->assertNotEmpty($event->broadcastOn(), 'control: the wider entry puts the event on the channel');
        $this->assertSame([self::ALL], $event->broadcastWith()['__subscriptions']);
    }

    private function makeEvent(string $eventName): AuditRequestUpdatedEvent
    {
        $auditRequest     = new AuditRequest(['team_id' => 1, 'url' => '/test']);
        $auditRequest->id = 1;

        return new AuditRequestUpdatedEvent($auditRequest, $eventName);
    }

    /** A filter entry in the shape the subscription service writes: members, definition, index. */
    private function putFilter(string $hash, array $filter, array $userIds): void
    {
        $key = "subscribe:AuditRequest:1:filter:{$hash}";
        Cache::put($key, $userIds);
        Cache::put("{$key}:definition", $filter);
        Cache::put('subscribe:AuditRequest:1:filters', [...Cache::get('subscribe:AuditRequest:1:filters', []), $hash]);
    }

    /** broadcastOn() first, then the payload — the order Laravel's BroadcastEvent::handle() uses. */
    private function subscriptionsNamedBy(AuditRequestUpdatedEvent $event): array
    {
        $this->assertNotEmpty($event->broadcastOn(), 'control: something subscribed, so the event is sent');

        $payload = $event->broadcastWith();
        $this->assertArrayNotHasKey('__stale', $payload, 'control: not a dedup skip');
        $this->assertArrayHasKey('__subscriptions', $payload, 'the payload must name the subscriptions it satisfied');

        return $payload['__subscriptions'];
    }
}
