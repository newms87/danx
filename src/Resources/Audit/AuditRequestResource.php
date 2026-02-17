<?php

namespace Newms87\Danx\Resources\Audit;

use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Resources\ActionResource;
use Newms87\Danx\Resources\Job\JobDispatchResource;

class AuditRequestResource extends ActionResource
{
    /**
     * Traces the ancestor chain from the given audit request up to the root.
     * Returns an ordered array of audit request IDs from root to the current request (inclusive).
     *
     * Uses the direct parent_id chain. Falls back to the legacy JobDispatch chain
     * for audit requests created before parent_id was added.
     */
    public static function resolveAncestorIds(AuditRequest $auditRequest): array
    {
        $ids     = [$auditRequest->id];
        $current = $auditRequest;
        $limit   = 20;

        while ($limit-- > 0) {
            // Prefer direct parent_id link
            if ($current->parent_id) {
                $parent = AuditRequest::find($current->parent_id);

                if (!$parent) {
                    break;
                }

                $ids[]   = $parent->id;
                $current = $parent;

                continue;
            }

            // Legacy fallback: trace through JobDispatch chain
            $ranJob = $current->ranJobs()->first();

            if (!$ranJob || !$ranJob->dispatch_audit_request_id) {
                break;
            }

            $parent = AuditRequest::find($ranJob->dispatch_audit_request_id);

            if (!$parent) {
                break;
            }

            $ids[]   = $parent->id;
            $current = $parent;
        }

        return array_reverse($ids);
    }

    public static function data(AuditRequest $auditRequest, array $includeFields = []): array
    {
        return [
            'id'                    => $auditRequest->id,
            'parent_id'             => $auditRequest->parent_id,
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
            'children_count'        => $auditRequest->children_count,
            'created_at'            => $auditRequest->created_at,
            'updated_at'            => $auditRequest->updated_at,

            'ancestor_ids' => fn() => static::resolveAncestorIds($auditRequest),

            'audits'          => fn() => AuditResource::collection($auditRequest->audits),
            'api_logs'        => fn() => ApiLogResource::collection($auditRequest->apiLogs),
            'ran_jobs'        => fn() => JobDispatchResource::collection($auditRequest->ranJobs),
            'dispatched_jobs' => fn() => JobDispatchResource::collection($auditRequest->dispatchedJobs),
            'errors'          => fn() => ErrorLogEntryResource::collection($auditRequest->errorLogEntries),
            'children'        => fn() => static::collection($auditRequest->children),
        ];
    }
}
