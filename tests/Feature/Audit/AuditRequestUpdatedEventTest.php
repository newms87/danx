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

		$expectedFields = [
			'logs',
			'audits_count',
			'api_logs_count',
			'ran_jobs_count',
			'dispatched_jobs_count',
			'errors_count',
			'children_count',
			'updated_at',
		];

		foreach ($expectedFields as $field) {
			$this->assertArrayHasKey($field, $data, "Missing expected field: $field");
		}

		// Verify full relation data is NOT included (slim payload)
		$excludedFields = ['request', 'response', 'ran_jobs', 'api_logs', 'errors', 'children'];
		foreach ($excludedFields as $field) {
			$this->assertArrayNotHasKey($field, $data, "Unexpected field in slim payload: $field");
		}
	}
}
