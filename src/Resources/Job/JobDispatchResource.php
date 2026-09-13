<?php

namespace Newms87\Danx\Resources\Job;

use Illuminate\Database\Eloquent\Model;
use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Resources\ActionResource;
use Newms87\Danx\Resources\Audit\ApiLogResource;
use Newms87\Danx\Resources\Audit\ErrorLogEntryResource;

class JobDispatchResource extends ActionResource
{
    /**
     * A details() call that names no fields defaults to this row's own columns only —
     * no logs, errors or ApiLogs (all closures below, and per ActionResource::make(),
     * a closure is included only when explicitly named). The base
     * ActionResource::details() default of ['*' => true] would force all three on,
     * bodies included (SG-485). Callers that need one ask for it by name, e.g.
     * static::details($jobDispatch, ['apiLogs' => true]).
     */
    public static function details(Model $model, ?array $includeFields = null): array
    {
        return static::make($model, $includeFields ?? []);
    }

    public static function data(JobDispatch $jobDispatch, array $includeFields = []): array
    {
        // Safety check: if job is running but has timed out, update its status
        if ($jobDispatch->status === JobDispatch::STATUS_RUNNING && $jobDispatch->isTimedOut()) {
            $jobDispatch->timeout();
        }

        return [
            'name'                      => $jobDispatch->name,
            'ref'                       => $jobDispatch->ref,
            'job_batch_id'              => $jobDispatch->job_batch_id,
            'running_audit_request_id'  => $jobDispatch->running_audit_request_id,
            'dispatch_audit_request_id' => $jobDispatch->dispatch_audit_request_id,
            'status'                    => $jobDispatch->status,
            'ran_at'                    => $jobDispatch->ran_at,
            'completed_at'              => $jobDispatch->completed_at,
            'will_timeout_at'           => $jobDispatch->will_timeout_at,
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
