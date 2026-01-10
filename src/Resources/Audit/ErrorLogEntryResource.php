<?php

namespace Newms87\Danx\Resources\Audit;

use Newms87\Danx\Models\Audit\ErrorLogEntry;
use Newms87\Danx\Resources\ActionResource;

class ErrorLogEntryResource extends ActionResource
{
    public static function data(ErrorLogEntry $errorLogEntry, array $includeFields = []): array
    {
        return [
            'id'               => $errorLogEntry->id,
            'audit_request_id' => $errorLogEntry->audit_request_id,
            'error_class'      => $errorLogEntry->errorLog->error_class,
            'code'             => $errorLogEntry->errorLog->code,
            'level'            => $errorLogEntry->errorLog->level,
            'last_seen_at'     => $errorLogEntry->errorLog->last_seen_at,
            'file'             => $errorLogEntry->errorLog->file,
            'line'             => $errorLogEntry->errorLog->line,
            'message'          => $errorLogEntry->full_message,
            'data'             => $errorLogEntry->data,
            'stack_trace'      => $errorLogEntry->errorLog->stack_trace,
            'created_at'       => $errorLogEntry->created_at,
        ];
    }
}
