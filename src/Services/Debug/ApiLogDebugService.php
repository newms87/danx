<?php

namespace Newms87\Danx\Services\Debug;

use Closure;
use Illuminate\Console\Command;
use Newms87\Danx\Models\Audit\ApiLog;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Services\Debug\Concerns\DebugOutputHelper;

/**
 * Handles API log listing and detail views for debugging.
 */
class ApiLogDebugService
{
    use DebugOutputHelper;

    private const int DEFAULT_URL_TRUNCATE = 40;

    private const int DEFAULT_JSON_TRUNCATE = 2000;

    private const int DEFAULT_STACK_TRACE_FRAMES = 15;

    /**
     * List API logs for an audit request with optional filtering.
     *
     * Filters supported:
     * - 'service' => LIKE match on service_name
     * - 'class' => LIKE match on api_class
     * - 'status' => Parse status filter (e.g., "400", ">=400", "4xx", "error")
     * - 'slow' => Filter where run_time_ms > N
     */
    public function listApiLogs(AuditRequest $auditRequest, array $filters, Command $command, bool $json = false): void
    {
        $query = $auditRequest->apiLogs();

        // Apply filters
        if (!empty($filters['service'])) {
            $query->where('service_name', 'ILIKE', '%' . $filters['service'] . '%');
        }

        if (!empty($filters['class'])) {
            $query->where('api_class', 'ILIKE', '%' . $filters['class'] . '%');
        }

        if (!empty($filters['status'])) {
            $statusFilter = $this->parseStatusFilter($filters['status']);
            $query->where($statusFilter);
        }

        if (!empty($filters['slow'])) {
            $query->where('run_time_ms', '>', (int)$filters['slow']);
        }

        $apiLogs = $query->orderBy('started_at')->get();

        if ($json) {
            $this->outputJsonList($apiLogs, $command);

            return;
        }

        $this->showHeader("API Logs for Audit Request #{$auditRequest->id}", $command);

        if ($apiLogs->isEmpty()) {
            $command->line('No API logs found matching the filters.');

            return;
        }

        $this->renderApiLogsTable($apiLogs, $command);
        $this->showApiLogsSummary($apiLogs, $command);

        $command->newLine();
        $command->comment('Use --api-id=ID for full request/response details');
    }

    /**
     * List most recent API logs globally (not tied to a specific audit request).
     *
     * Filters supported:
     * - 'service' => LIKE match on service_name
     * - 'class' => LIKE match on api_class
     * - 'status' => Parse status filter
     * - 'slow' => Filter where run_time_ms > N
     */
    public function listRecentApiLogs(int $limit, array $filters, Command $command, bool $json = false): void
    {
        $query = ApiLog::query()->orderByDesc('id');

        // Apply filters
        if (!empty($filters['service'])) {
            $query->where('service_name', 'ILIKE', '%' . $filters['service'] . '%');
        }

        if (!empty($filters['class'])) {
            $query->where('api_class', 'ILIKE', '%' . $filters['class'] . '%');
        }

        if (!empty($filters['status'])) {
            $statusFilter = $this->parseStatusFilter($filters['status']);
            $query->where($statusFilter);
        }

        if (!empty($filters['slow'])) {
            $query->where('run_time_ms', '>', (int)$filters['slow']);
        }

        $apiLogs = $query->limit($limit)->get();

        if ($json) {
            $this->outputJsonList($apiLogs, $command);

            return;
        }

        $this->showHeader('Recent API Logs', $command);

        if ($apiLogs->isEmpty()) {
            $command->line('No API logs found matching the filters.');

            return;
        }

        $this->renderRecentApiLogsTable($apiLogs, $command);
        $this->showApiLogsSummary($apiLogs, $command);

        $command->newLine();
        $command->comment('Use --api-id=ID for full request/response details');
    }

    /**
     * Show detailed information for a specific API log.
     */
    public function showApiLogDetail(int $apiLogId, Command $command, bool $full = false): void
    {
        $apiLog = ApiLog::find($apiLogId);

        if (!$apiLog) {
            $command->error("API Log #{$apiLogId} not found.");

            return;
        }

        $maxLength = $full ? PHP_INT_MAX : self::DEFAULT_JSON_TRUNCATE;
        $maxFrames = $full ? PHP_INT_MAX : self::DEFAULT_STACK_TRACE_FRAMES;

        $this->showHeader("API Log #{$apiLog->id}", $command);

        // Basic info
        $command->line("Service: {$apiLog->service_name} ({$apiLog->api_class})");
        $command->line("Method/URL: {$apiLog->method} {$apiLog->full_url}");
        $command->line('Status: ' . $this->colorizeStatus($apiLog->status_code ?? 0));

        // Timing
        $started  = $this->formatTimestamp($apiLog->started_at);
        $finished = $this->formatTimestamp($apiLog->finished_at);
        $duration = $apiLog->run_time_ms ? $this->formatDuration($apiLog->run_time_ms) : '-';
        $command->line("Timing: Started: {$started} | Finished: {$finished} | Duration: {$duration}");

        $command->newLine();

        // Request Headers
        $this->showSubHeader('Request Headers', $command);
        $this->showJsonContent($apiLog->request_headers, $command, $maxLength);

        $command->newLine();

        // Request Body
        $this->showSubHeader('Request Body', $command);
        $this->showJsonContent($apiLog->request, $command, $maxLength);

        $command->newLine();

        // Response Headers
        $this->showSubHeader('Response Headers', $command);
        $this->showJsonContent($apiLog->response_headers, $command, $maxLength);

        $command->newLine();

        // Response Body
        $this->showSubHeader('Response Body', $command);
        $this->showJsonContent($apiLog->response, $command, $maxLength);

        // Stack Trace (if exists)
        if ($apiLog->stack_trace) {
            $command->newLine();
            $this->showSubHeader('Stack Trace', $command);
            $this->showStackTrace($apiLog->stack_trace, $command, $maxFrames);
        }
    }

    /**
     * Parse a status filter string into a closure for query filtering.
     *
     * Supports:
     * - "400" => exact match
     * - ">=400" => greater than or equal
     * - ">400" => greater than
     * - "4xx" => range 400-499
     * - "5xx" => range 500-599
     * - "error" or "errors" => status >= 400
     */
    public function parseStatusFilter(string $status): Closure
    {
        $status = strtolower(trim($status));

        // Handle "error" or "errors"
        if ($status === 'error' || $status === 'errors') {
            return fn($query) => $query->where('status_code', '>=', 400);
        }

        // Handle "4xx" or "5xx" patterns
        if (preg_match('/^([45])xx$/i', $status, $matches)) {
            $base = (int)$matches[1] * 100;

            return fn($query) => $query->whereBetween('status_code', [$base, $base + 99]);
        }

        // Handle ">=" operator
        if (preg_match('/^>=(\d+)$/', $status, $matches)) {
            return fn($query) => $query->where('status_code', '>=', (int)$matches[1]);
        }

        // Handle ">" operator
        if (preg_match('/^>(\d+)$/', $status, $matches)) {
            return fn($query) => $query->where('status_code', '>', (int)$matches[1]);
        }

        // Handle exact numeric match
        if (is_numeric($status)) {
            return fn($query) => $query->where('status_code', (int)$status);
        }

        // Default: no filter
        return fn($query) => $query;
    }

    /**
     * Render API logs as a table.
     */
    private function renderApiLogsTable($apiLogs, Command $command): void
    {
        $rows = $apiLogs->map(fn(ApiLog $log) => [
            $log->id,
            $log->service_name ?? '-',
            $log->method       ?? '-',
            $this->colorizeStatus($log->status_code ?? 0),
            $this->truncate($log->url ?? '', self::DEFAULT_URL_TRUNCATE),
            $log->run_time_ms ? $this->formatDuration($log->run_time_ms) : '-',
        ])->toArray();

        $command->table(
            ['ID', 'Service', 'Method', 'Status', 'URL', 'Time'],
            $rows
        );
    }

    /**
     * Render recent API logs as a table (includes Audit Request ID).
     */
    private function renderRecentApiLogsTable($apiLogs, Command $command): void
    {
        $rows = $apiLogs->map(fn(ApiLog $log) => [
            $log->id,
            $log->audit_request_id ?? '-',
            $log->service_name     ?? '-',
            $log->method           ?? '-',
            $this->colorizeStatus($log->status_code ?? 0),
            $this->truncate($log->url ?? '', self::DEFAULT_URL_TRUNCATE),
            $log->run_time_ms ? $this->formatDuration($log->run_time_ms) : '-',
            $this->formatTimestamp($log->created_at),
        ])->toArray();

        $command->table(
            ['ID', 'AR ID', 'Service', 'Method', 'Status', 'URL', 'Time', 'Created'],
            $rows
        );
    }

    /**
     * Show summary statistics for API logs.
     */
    private function showApiLogsSummary($apiLogs, Command $command): void
    {
        $total      = $apiLogs->count();
        $errors     = $apiLogs->filter(fn(ApiLog $log) => ($log->status_code ?? 0) >= 400)->count();
        $totalTime  = $apiLogs->sum('run_time_ms');
        $avgTime    = $total > 0 ? (int)round($totalTime / $total) : 0;

        $command->line("Total: {$total} API logs ({$errors} errors, avg {$avgTime}ms)");
    }

    /**
     * Output API logs as JSON.
     */
    private function outputJsonList($apiLogs, Command $command): void
    {
        $data = $apiLogs->map(fn(ApiLog $log) => [
            'id'           => $log->id,
            'service_name' => $log->service_name,
            'api_class'    => $log->api_class,
            'method'       => $log->method,
            'url'          => $log->url,
            'full_url'     => $log->full_url,
            'status_code'  => $log->status_code,
            'run_time_ms'  => $log->run_time_ms,
            'started_at'   => $log->started_at?->toIso8601String(),
            'finished_at'  => $log->finished_at?->toIso8601String(),
        ])->toArray();

        $command->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Display stack trace frames.
     */
    private function showStackTrace(array $stackTrace, Command $command, int $maxFrames): void
    {
        $frames = array_slice($stackTrace, 0, $maxFrames);

        foreach ($frames as $index => $frame) {
            $file     = $frame['file']     ?? '[internal]';
            $line     = $frame['line']     ?? '?';
            $function = $frame['function'] ?? '?';
            $class    = $frame['class']    ?? '';
            $type     = $frame['type']     ?? '';

            $caller = $class ? "{$class}{$type}{$function}" : $function;
            $command->line("    #{$index} {$file}:{$line} {$caller}()");
        }

        if (count($stackTrace) > $maxFrames) {
            $remaining = count($stackTrace) - $maxFrames;
            $command->comment("    ... and {$remaining} more frames (use --full to see all)");
        }
    }
}
