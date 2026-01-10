<?php

namespace Newms87\Danx\Resources\Audit;

use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Resources\ActionResource;
use Newms87\Danx\Resources\Job\JobDispatchResource;

class AuditRequestResource extends ActionResource
{
    public static function data(AuditRequest $auditRequest, array $includeFields = []): array
    {
        return [
            'id'                    => $auditRequest->id,
            'session_id'            => $auditRequest->session_id,
            'user_name'             => $auditRequest->user ? $auditRequest->user->email . ' (' . $auditRequest->user_id . ')' : 'N/A',
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

            'audits'          => fn() => AuditResource::collection($auditRequest->audits),
            'api_logs'        => fn() => ApiLogResource::collection($auditRequest->apiLogs),
            'ran_jobs'        => fn() => JobDispatchResource::collection($auditRequest->ranJobs),
            'dispatched_jobs' => fn() => JobDispatchResource::collection($auditRequest->dispatchedJobs),
            'errors'          => fn() => ErrorLogEntryResource::collection($auditRequest->errorLogEntries),
        ];
    }
}
