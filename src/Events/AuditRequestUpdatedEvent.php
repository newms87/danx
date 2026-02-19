<?php

namespace Newms87\Danx\Events;

use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Resources\Audit\AuditRequestResource;

/**
 * Broadcasting event for AuditRequest updates.
 *
 * Fires when an AuditRequest's logs, counts, or response change (see AuditRequest::booted()).
 * Broadcasts a slim payload containing only the fields needed for real-time tab badge updates
 * and log streaming in the AuditRequestNavigator frontend component.
 */
class AuditRequestUpdatedEvent extends ModelSavedEvent
{
	public function __construct(protected AuditRequest $auditRequest, protected string $event)
	{
		parent::__construct(
			$auditRequest,
			$event,
			AuditRequestResource::class,
			$auditRequest->team_id
		);
	}

	protected function updatedData(): array
	{
		return AuditRequestResource::make($this->auditRequest, [
			'*'                     => false,
			'audits_count'          => true,
			'api_logs_count'        => true,
			'ran_jobs_count'        => true,
			'dispatched_jobs_count' => true,
			'errors_count'          => true,
			'children_count'        => true,
			'log_line_count'        => true,
			'time'                  => true,
			'updated_at'            => true,
		]);
	}
}
