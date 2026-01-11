<?php

namespace Newms87\Danx\Resources\Job;

use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Resources\ActionResource;
use Newms87\Danx\Resources\Audit\ApiLogResource;
use Newms87\Danx\Resources\Audit\ErrorLogEntryResource;

class JobDispatchResource extends ActionResource
{
    public static function data(JobDispatch $jobDispatch, array $includeFields = []): array
    {
        return [
            'name'                      => $jobDispatch->name,
            'ref'                       => $jobDispatch->ref,
            'job_batch_id'              => $jobDispatch->job_batch_id,
            'running_audit_request_id'  => $jobDispatch->running_audit_request_id,
            'dispatch_audit_request_id' => $jobDispatch->dispatch_audit_request_id,
            'status'                    => $jobDispatch->status,
            'ran_at'                    => $jobDispatch->ran_at,
            'completed_at'              => $jobDispatch->completed_at,
            'timeout_at'                => $jobDispatch->timeout_at,
            'run_time_ms'               => $jobDispatch->run_time_ms,
            'count'                     => $jobDispatch->count,
            'created_at'                => $jobDispatch->created_at,

            'api_log_count'   => $jobDispatch->runningAuditRequest?->api_log_count   ?? 0,
            'error_log_count' => $jobDispatch->runningAuditRequest?->error_log_count ?? 0,
            'log_line_count'  => $jobDispatch->runningAuditRequest?->log_line_count  ?? 0,

            'logs'    => fn() => $jobDispatch->runningAuditRequest?->logs ?? '',
            'errors'  => fn($fields) => ErrorLogEntryResource::collection($jobDispatch->runningAuditRequest?->errorLogEntries, $fields),
            'apiLogs' => fn($fields) => ApiLogResource::collection($jobDispatch->runningAuditRequest?->apiLogs, $fields),
        ];
    }
}
