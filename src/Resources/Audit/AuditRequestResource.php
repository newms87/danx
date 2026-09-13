<?php

namespace Newms87\Danx\Resources\Audit;

use Illuminate\Database\Eloquent\Model;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Resources\ActionResource;
use Newms87\Danx\Resources\Job\JobDispatchResource;

class AuditRequestResource extends ActionResource
{
    /**
     * A details() call that names no fields defaults to this row's own columns only —
     * no ApiLogs, audits, jobs, errors or children. Those are all closures (see data()
     * below and ActionResource::make()'s laziness rule: a callable field is included
     * only when explicitly named), so the base ActionResource::details() default of
     * ['*' => true] would force every one of them on, including every ApiLog's full
     * request/response bodies. One production AuditRequest exceeded 6MB that way,
     * over Lambda's response limit (SG-485). Callers that need a relation ask for it
     * by name, e.g. static::details($auditRequest, ['api_logs' => true]).
     */
    public static function details(Model $model, ?array $includeFields = null): array
    {
        return static::make($model, $includeFields ?? []);
    }

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

            // @todo Remove legacy JobDispatch fallback once existing audit_request records are backfilled with parent_id
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
            'logs'                  => fn() => $auditRequest->logs,
            'time'                  => $auditRequest->time,
            'audits_count'          => $auditRequest->audits()->count(),
            'api_logs_count'        => $auditRequest->apiLogs()->count(),
            'ran_jobs_count'        => $auditRequest->ranJobs()->count(),
            'dispatched_jobs_count' => $auditRequest->dispatchedJobs()->count(),
            'errors_count'          => $auditRequest->errorLogEntries()->count(),
            'children_count'        => $auditRequest->children()->count(),
            'log_line_count'        => $auditRequest->log_line_count,
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
