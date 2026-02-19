<?php

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\Event;
use Newms87\Danx\Events\AuditRequestUpdatedEvent;
use Newms87\Danx\Models\Audit\AuditRequest;
use Tests\TestCase;

/**
 * Tests for AuditRequestUpdatedEvent broadcasting.
 *
 * Verifies the event fires on any save and that the broadcast payload
 * contains the expected slim subset of fields for real-time UI updates.
 */
class AuditRequestUpdatedEventTest extends TestCase
{
	public function test_fires_on_any_save(): void
	{
		Event::fake([AuditRequestUpdatedEvent::class]);

		$auditRequest = AuditRequest::create(['team_id' => 1, 'url' => '/test']);
		Event::assertNotDispatched(AuditRequestUpdatedEvent::class);

		$auditRequest->update(['url' => '/updated']);

		Event::assertDispatched(AuditRequestUpdatedEvent::class);
	}

	public function test_updated_data_contains_expected_fields(): void
	{
		$auditRequest = AuditRequest::create([
			'team_id' => 1,
			'url'     => '/test',
			'logs'    => "Line 1\nLine 2",
		]);

		$event = new AuditRequestUpdatedEvent($auditRequest, 'updated');
		$data  = $event->data();

		// The broadcast payload must be slim — no heavy fields that would bloat WebSocket messages
		$heavyFields = ['logs', 'request', 'response', 'ran_jobs', 'api_logs', 'errors', 'children'];
		foreach ($heavyFields as $field) {
			$this->assertArrayNotHasKey($field, $data, "Heavy field '$field' should not be in broadcast payload");
		}
	}
}
