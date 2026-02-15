<?php

namespace Newms87\Danx\Resources\Audit;

use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Resources\ActionResource;
use Newms87\Danx\Resources\Job\JobDispatchResource;

class AuditRequestResource extends ActionResource
{
    /**
     * Traces the ancestor chain from the given audit request up to the root HTTP request.
     * Returns an ordered array of audit request IDs from root to the current request (inclusive).
     *
     * The chain is resolved by following ranJobs → dispatch_audit_request_id links:
     * each audit request that ran a job was dispatched by a parent audit request.
     * The root is an HTTP request with no ranJobs (it was not dispatched by another request).
     */
    public static function resolveAncestorIds(AuditRequest $auditRequest): array
    {
        $ids     = [$auditRequest->id];
        $current = $auditRequest;
        $limit   = 20;

        while ($limit-- > 0) {
            $ranJob = $current->ranJobs()->first();

            if (!$ranJob || !$ranJob->dispatch_audit_request_id) {
                break;
            }

            $parent = AuditRequest::find($ranJob->dispatch_audit_request_id);

            if (!$parent) {
                break;
            }

            $ids[]  = $parent->id;
            $current = $parent;
        }

        return array_reverse($ids);
    }

    public static function data(AuditRequest $auditRequest, array $includeFields = []): array
    {
        return [
            'id'                    => $auditRequest->id,
            'session_id'            => $auditRequest->session_id,
            'user_id'               => $auditRequest->user_id,
            'user_name'             => $auditRequest->user ? $auditRequest->user->email . ' (' . $auditRequest->user_id . ')' : 'N/A',
            'team_id'               => $auditRequest->team_id,
            'team_name'             => $auditRequest->team?->name,
            'environment'           => $auditRequest->environment,
            'http_method'           => $auditRequest->requestMethod(),
            'http_status_code'      => $auditRequest->statusCode(),
            'url'                   => $auditRequest->url,
            'request'               => $auditRequest->request,
            'response'              => $auditRequest->response,
            'response_length'       => $auditRequest->response ? $auditRequest->response['length'] : 0,
            'max_memory'            => $auditRequest->response ? $auditRequest->response['max_memory_used'] : 0,
            'logs'                  => $auditRequest->logs,
            'time'                  => $auditRequest->time,
            'audits_count'          => $auditRequest->audits()->count(),
            'api_logs_count'        => $auditRequest->apiLogs()->count(),
            'ran_jobs_count'        => $auditRequest->ranJobs()->count(),
            'dispatched_jobs_count' => $auditRequest->dispatchedJobs()->count(),
            'errors_count'          => $auditRequest->errorLogEntries()->count(),
            'created_at'            => $auditRequest->created_at,
            'updated_at'            => $auditRequest->updated_at,

            'ancestor_ids' => fn() => static::resolveAncestorIds($auditRequest),

            'audits'          => fn() => AuditResource::collection($auditRequest->audits),
            'api_logs'        => fn() => ApiLogResource::collection($auditRequest->apiLogs),
            'ran_jobs'        => fn() => JobDispatchResource::collection($auditRequest->ranJobs),
            'dispatched_jobs' => fn() => JobDispatchResource::collection($auditRequest->dispatchedJobs),
            'errors'          => fn() => ErrorLogEntryResource::collection($auditRequest->errorLogEntries),
        ];
    }
}
