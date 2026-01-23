<?php

namespace Newms87\Danx\Events;

use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Resources\Job\JobDispatchResource;

class JobDispatchUpdatedEvent extends ModelSavedEvent
{
    public function __construct(protected JobDispatch $jobDispatch, protected string $event)
    {
        parent::__construct(
            $jobDispatch,
            $event,
            JobDispatchResource::class,
            $jobDispatch->team_id
        );
    }

    protected function createdData(): array
    {
        return JobDispatchResource::make($this->jobDispatch, [
            '*'            => false,
            'name'         => true,
            'ref'          => true,
            'job_batch_id' => true,
            'status'       => true,
            'will_timeout_at' => true,
            'created_at'   => true,
        ]);
    }

    protected function updatedData(): array
    {
        return JobDispatchResource::make($this->jobDispatch, [
            '*'                         => false,
            'status'                    => true,
            'running_audit_request_id'  => true,
            'dispatch_audit_request_id' => true,
            'ran_at'                    => true,
            'completed_at'              => true,
            'run_time_ms'               => true,
            'count'                     => true,
            'api_log_count'             => true,
            'error_log_count'           => true,
            'log_line_count'            => true,
        ]);
    }
}
