<?php

namespace Newms87\Danx\Services\Debug;

use Illuminate\Console\Command;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Services\Debug\Concerns\DebugOutputHelper;

/**
 * Main orchestrator service for audit debugging.
 *
 * Handles overview display, recent requests listing, and request log viewing
 * for AuditRequest records.
 */
class AuditDebugService
{
    use DebugOutputHelper;

    private const int DEFAULT_LOGS_TRUNCATE_LENGTH = 5000;

    private const array FAILED_JOB_STATUSES = [
        JobDispatch::STATUS_EXCEPTION,
        JobDispatch::STATUS_FAILED,
        JobDispatch::STATUS_TIMEOUT,
        JobDispatch::STATUS_ABORTED,
    ];

    /**
     * Find an AuditRequest by ID, returning null with error message if not found.
     */
    public function findAuditRequest(int $id, Command $command): ?AuditRequest
    {
        $auditRequest = AuditRequest::find($id);

        if (!$auditRequest) {
            $command->error("Audit Request #{$id} not found.");

            return null;
        }

        return $auditRequest;
    }

    /**
     * Display a summary overview of the audit request.
     */
    public function showOverview(AuditRequest $auditRequest, Command $command, bool $json = false): void
    {
        $auditRequest->loadCount(['apiLogs', 'errorLogEntries', 'ranJobs', 'dispatchedJobs', 'audits']);
        $auditRequest->load('user');

        $errorApiLogsCount = $auditRequest->apiLogs()->where('status_code', '>=', 400)->count();
        $failedJobsCount   = $auditRequest->ranJobs()->whereIn('status', self::FAILED_JOB_STATUSES)->count();

        $teamName = $this->resolveTeamName($auditRequest);

        if ($json) {
            $this->outputOverviewJson($auditRequest, $errorApiLogsCount, $failedJobsCount, $teamName, $command);

            return;
        }

        $this->showHeader("Audit Request #{$auditRequest->id}", $command);

        // URL with method
        $method = $auditRequest->requestMethod() ?? 'GET';
        $command->line("{$method} {$auditRequest->url}");

        // Status code and time
        $statusCode      = $auditRequest->statusCode() ?? 0;
        $colorizedStatus = $this->colorizeStatus($statusCode);
        $formattedTime   = $auditRequest->time ? $this->formatDuration((int)($auditRequest->time * 1000)) : '-';
        $command->line("Status: {$colorizedStatus} | Time: {$formattedTime}");

        // User info
        if ($auditRequest->user) {
            $command->line("User: {$auditRequest->user->email} (ID: {$auditRequest->user_id})");
        }

        // Team info
        if ($auditRequest->team_id && $teamName) {
            $command->line("Team: {$teamName} (ID: {$auditRequest->team_id})");
        }

        // Session ID (truncated)
        if ($auditRequest->session_id) {
            $truncatedSession = $this->truncate($auditRequest->session_id, 32, '...');
            $command->line("Session: {$truncatedSession}");
        }

        // Environment
        if ($auditRequest->environment) {
            $command->line("Environment: {$auditRequest->environment}");
        }

        // Created timestamp
        $command->line("Created: {$this->formatTimestamp($auditRequest->created_at)}");

        $command->newLine();

        // Summary counts
        $command->line('<fg=cyan>Summary:</>');
        $this->displayCountWithErrors($command, 'API Logs', $auditRequest->api_logs_count, $errorApiLogsCount);
        $this->displayCountWithErrors($command, 'Jobs Ran', $auditRequest->ran_jobs_count, $failedJobsCount);
        $command->line("  Errors: {$auditRequest->error_log_entries_count}");
        $command->line("  Model Changes: {$auditRequest->audits_count}");

        $command->newLine();
        $command->comment('Use --api-logs, --jobs, --errors, --audits, or --logs for details');
    }

    /**
     * Display the server logs (TEXT field) from the audit request.
     */
    public function showRequestLogs(AuditRequest $auditRequest, Command $command, bool $full = false): void
    {
        $this->showHeader("Request Logs for Audit Request #{$auditRequest->id}", $command);

        if (empty($auditRequest->logs)) {
            $command->line('No logs recorded');

            return;
        }

        $logs = $auditRequest->logs;

        if (!$full) {
            $logs = $this->truncate($logs, self::DEFAULT_LOGS_TRUNCATE_LENGTH);
        }

        // Apply colorization to log levels
        $colorizedLogs = $this->colorizeLogs($logs);

        $command->line($colorizedLogs);
    }

    /**
     * List most recent audit requests with optional filtering.
     *
     * @param  array{url?: string, user?: int, team?: int, session?: string}  $filters
     */
    public function listRecentRequests(int $limit, array $filters, Command $command, bool $json = false): void
    {
        $query = AuditRequest::query()
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        $this->applyFilters($query, $filters);

        $auditRequests = $query->get();
        $totalCount    = AuditRequest::query()->tap(fn($q) => $this->applyFilters($q, $filters))->count();

        if ($json) {
            $this->outputRecentRequestsJson($auditRequests, $totalCount, $command);

            return;
        }

        if ($auditRequests->isEmpty()) {
            $command->info('No audit requests found matching the criteria.');

            return;
        }

        $tableData = $auditRequests->map(fn(AuditRequest $ar) => [
            'ID'      => $ar->id,
            'Method'  => $ar->requestMethod() ?? '-',
            'Status'  => $ar->statusCode()    ?? '-',
            'URL'     => $this->truncate($ar->url ?? '-', 50, '...'),
            'Time'    => $ar->time ? $this->formatDuration((int)($ar->time * 1000)) : '-',
            'Created' => $this->formatTimestamp($ar->created_at),
        ])->toArray();

        $command->table(['ID', 'Method', 'Status', 'URL', 'Time', 'Created'], $tableData);
        $command->line("Total: {$totalCount} requests");
    }

    /**
     * Resolve team name from team_id.
     */
    private function resolveTeamName(AuditRequest $auditRequest): ?string
    {
        if (!$auditRequest->team_id) {
            return null;
        }

        $teamClass = config('danx.models.team', \Newms87\Danx\Models\Team\Team::class);

        $team = $teamClass::find($auditRequest->team_id);

        return $team?->name ?? null;
    }

    /**
     * Display a count with error count in parentheses if errors exist.
     */
    private function displayCountWithErrors(Command $command, string $label, int $count, int $errorCount): void
    {
        $errorSuffix = $errorCount > 0 ? " (<fg=red>{$errorCount} errors</>)" : '';
        $command->line("  {$label}: {$count}{$errorSuffix}");
    }

    /**
     * Colorize log output based on detected log levels.
     */
    private function colorizeLogs(string $logs): string
    {
        $patterns = [
            '/\bDEBUG\b/'     => '<fg=gray>DEBUG</>',
            '/\bINFO\b/'      => '<fg=gray>INFO</>',
            '/\bNOTICE\b/'    => '<fg=cyan>NOTICE</>',
            '/\bWARNING\b/'   => '<fg=yellow>WARNING</>',
            '/\bERROR\b/'     => '<fg=red>ERROR</>',
            '/\bCRITICAL\b/'  => '<fg=bright-red>CRITICAL</>',
            '/\bALERT\b/'     => '<fg=bright-red>ALERT</>',
            '/\bEMERGENCY\b/' => '<fg=bright-red>EMERGENCY</>',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $logs);
    }

    /**
     * Apply filters to the audit request query.
     *
     * @param  array{url?: string, user?: int, team?: int, session?: string}  $filters
     */
    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['url'])) {
            $query->where('url', 'ILIKE', "%{$filters['url']}%");
        }

        if (!empty($filters['user'])) {
            $query->where('user_id', $filters['user']);
        }

        if (!empty($filters['team'])) {
            $query->where('team_id', $filters['team']);
        }

        if (!empty($filters['session'])) {
            $query->where('session_id', 'ILIKE', "%{$filters['session']}%");
        }
    }

    /**
     * Output overview as JSON.
     */
    private function outputOverviewJson(
        AuditRequest $auditRequest,
        int $errorApiLogsCount,
        int $failedJobsCount,
        ?string $teamName,
        Command $command
    ): void {
        $data = [
            'id'          => $auditRequest->id,
            'url'         => $auditRequest->url,
            'method'      => $auditRequest->requestMethod(),
            'status_code' => $auditRequest->statusCode(),
            'time'        => $auditRequest->time,
            'user_id'     => $auditRequest->user_id,
            'user_email'  => $auditRequest->user?->email,
            'team_id'     => $auditRequest->team_id,
            'team_name'   => $teamName,
            'session_id'  => $auditRequest->session_id,
            'environment' => $auditRequest->environment,
            'created_at'  => $auditRequest->created_at?->toIso8601String(),
            'counts'      => [
                'api_logs'        => $auditRequest->api_logs_count,
                'api_logs_errors' => $errorApiLogsCount,
                'ran_jobs'        => $auditRequest->ran_jobs_count,
                'ran_jobs_failed' => $failedJobsCount,
                'errors'          => $auditRequest->error_log_entries_count,
                'model_changes'   => $auditRequest->audits_count,
            ],
        ];

        $command->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Output recent requests list as JSON.
     */
    private function outputRecentRequestsJson($auditRequests, int $totalCount, Command $command): void
    {
        $data = [
            'total'    => $totalCount,
            'requests' => $auditRequests->map(fn(AuditRequest $ar) => [
                'id'         => $ar->id,
                'method'     => $ar->requestMethod(),
                'status'     => $ar->statusCode(),
                'url'        => $ar->url,
                'time'       => $ar->time,
                'created_at' => $ar->created_at?->toIso8601String(),
            ])->toArray(),
        ];

        $command->line(json_encode($data, JSON_PRETTY_PRINT));
    }
}
