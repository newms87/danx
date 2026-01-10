<?php

namespace Newms87\Danx\Console\Commands;

use Illuminate\Console\Command;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Services\Debug\ApiLogDebugService;
use Newms87\Danx\Services\Debug\AuditDebugService;
use Newms87\Danx\Services\Debug\AuditRecordDebugService;
use Newms87\Danx\Services\Debug\ErrorLogDebugService;
use Newms87\Danx\Services\Debug\JobDispatchDebugService;

/**
 * ===================================================================================
 * AUDIT DEBUG COMMAND - Comprehensive System Debugging Tool
 * ===================================================================================
 *
 * This command provides complete visibility into the audit system, allowing you to
 * debug and trace any request, job, API call, or error in the system.
 *
 * ===================================================================================
 * WHEN TO USE THIS COMMAND
 * ===================================================================================
 *
 * Use this command whenever you need to:
 * - Debug a failed job or task process
 * - Trace what happened during an HTTP request
 * - View external API calls (OpenAI, Stripe, etc.) with full request/response bodies
 * - Find and diagnose errors with full stack traces
 * - See what database changes occurred during an operation
 * - View server-side log output for a specific request
 *
 * ===================================================================================
 * KEY CONCEPTS
 * ===================================================================================
 *
 * AUDIT REQUEST (Central Hub)
 * - Every HTTP request and background job creates an AuditRequest record
 * - The AuditRequest ID is the key to accessing all related debugging data
 * - Contains: URL, user, team, timing, server logs, and links to all related data
 *
 * API LOGS
 * - Records of external API calls (OpenAI, Stripe, webhooks, etc.)
 * - Includes: full URL, headers, request body, response body, timing, status code
 * - Stack trace captured for failed requests (4xx/5xx status codes)
 *
 * JOB DISPATCHES
 * - Background job execution records
 * - Two relationships: "dispatched by" (who queued it) and "ran during" (execution context)
 * - Status: Pending, Running, Complete, Exception, Failed, Timeout, Aborted
 *
 * ERROR LOGS
 * - Aggregated exception records with deduplication via hash
 * - Hierarchical: supports exception chains (parent -> child relationships)
 * - Each unique error has entries for individual occurrences
 *
 * AUDIT RECORDS (Model Changes)
 * - ORM-level tracking of database changes via laravel-auditing
 * - Events: created, updated, deleted, restored
 * - Shows old_values vs new_values for each change
 *
 * SERVER LOGS (audit_request.logs)
 * - All Log::debug(), Log::info(), etc. output captured during the request
 * - Essential for understanding the step-by-step execution flow
 *
 * ===================================================================================
 * COMMON DEBUGGING WORKFLOWS
 * ===================================================================================
 *
 * 1. DEBUG A FAILED TASK/JOB:
 *    a. Find recent jobs:           ./vendor/bin/sail artisan audit:debug --recent-jobs=20
 *    b. Look at failed job:         ./vendor/bin/sail artisan audit:debug --job-id=<ID>
 *    c. Check audit request:        ./vendor/bin/sail artisan audit:debug <AR_ID>
 *    d. View server logs:           ./vendor/bin/sail artisan audit:debug <AR_ID> --logs
 *    e. View errors if any:         ./vendor/bin/sail artisan audit:debug <AR_ID> --errors
 *
 * 2. DEBUG AN API INTEGRATION:
 *    a. Find recent API calls:      ./vendor/bin/sail artisan audit:debug --recent-api-logs=20
 *    b. Filter by service:          ./vendor/bin/sail artisan audit:debug --recent-api-logs=20 --api-service=openai
 *    c. View full request/response: ./vendor/bin/sail artisan audit:debug --api-id=<ID> --full
 *
 * 3. INVESTIGATE AN ERROR:
 *    a. Find recent errors:         ./vendor/bin/sail artisan audit:debug --recent-errors=20
 *    b. View error with chain:      ./vendor/bin/sail artisan audit:debug --error-id=<ID>
 *    c. Find related request:       (error shows audit_request_id in entries)
 *
 * 4. TRACE A REQUEST END-TO-END:
 *    a. Find the request:           ./vendor/bin/sail artisan audit:debug --recent=20 --url="/api/something"
 *    b. Get overview:               ./vendor/bin/sail artisan audit:debug <ID>
 *    c. View server logs:           ./vendor/bin/sail artisan audit:debug <ID> --logs
 *    d. View API calls made:        ./vendor/bin/sail artisan audit:debug <ID> --api-logs
 *    e. View model changes:         ./vendor/bin/sail artisan audit:debug <ID> --audits
 *
 * ===================================================================================
 * OUTPUT MODES
 * ===================================================================================
 *
 * --full    Disables truncation, shows complete request/response bodies and stack traces
 * --json    Outputs structured JSON for programmatic parsing or piping to other tools
 *
 * ===================================================================================
 * FILTER OPTIONS
 * ===================================================================================
 *
 * For API logs:
 *   --api-service=<name>    Filter by service name (e.g., "openai", "stripe")
 *   --api-class=<class>     Filter by API class
 *   --api-status=<code>     Filter by status: "400", ">=400", "4xx", "5xx", "error"
 *   --api-slow=<ms>         Filter API calls slower than N milliseconds
 *
 * For jobs:
 *   --job-status=<status>   Filter: Pending, Running, Complete, Exception, Failed, Timeout
 *   --job-name=<pattern>    Filter by job name pattern
 *
 * For errors:
 *   --error-level=<level>   Minimum level: DEBUG, INFO, WARNING, ERROR, CRITICAL
 *   --error-class=<class>   Filter by exception class pattern
 *
 * For audit records:
 *   --audit-type=<type>     Filter by model type (e.g., "User", "Task")
 *   --audit-event=<event>   Filter: created, updated, deleted, restored
 *
 * For recent requests:
 *   --url=<pattern>         Filter by URL pattern
 *   --user=<id>             Filter by user ID
 *   --team=<id>             Filter by team ID
 *   --session=<id>          Filter by session ID
 *
 * ===================================================================================
 * ARCHITECTURE
 * ===================================================================================
 *
 * This command follows a thin-controller pattern:
 * - Command: Handles argument parsing and routing only (this file)
 * - Services: All business logic lives in dedicated debug services:
 *   - AuditDebugService: Overview, recent requests, server logs
 *   - ApiLogDebugService: API log listing and detail views
 *   - JobDispatchDebugService: Job dispatch listing and detail views
 *   - ErrorLogDebugService: Error log listing, detail, and chain visualization
 *   - AuditRecordDebugService: ORM audit record listing and diff views
 *
 * @see \Newms87\Danx\Services\Debug\AuditDebugService
 * @see \Newms87\Danx\Services\Debug\ApiLogDebugService
 * @see \Newms87\Danx\Services\Debug\JobDispatchDebugService
 * @see \Newms87\Danx\Services\Debug\ErrorLogDebugService
 * @see \Newms87\Danx\Services\Debug\AuditRecordDebugService
 */
class AuditDebugCommand extends Command
{
    protected $signature = 'audit:debug {audit-request? : AuditRequest ID to debug}
        {--overview : Show summary overview (default when ID provided)}
        {--logs : Show server logs (audit_request.logs TEXT field)}
        {--api-logs : Show API logs for this request}
        {--api-service= : Filter API logs by service name}
        {--api-class= : Filter API logs by API class}
        {--api-status= : Filter by status code (400, >=400, 4xx, 5xx)}
        {--api-slow= : Filter API logs slower than N milliseconds}
        {--api-id= : Show detailed API log by ID}
        {--jobs : List job dispatches for this request}
        {--job-status= : Filter jobs by status (Pending, Running, Complete, Exception, Failed, Timeout)}
        {--job-name= : Filter jobs by name pattern}
        {--job-id= : Show detailed job dispatch by ID}
        {--errors : Show error log entries for this request}
        {--error-level= : Filter by minimum level (DEBUG, INFO, WARNING, ERROR, CRITICAL)}
        {--error-class= : Filter errors by error class pattern}
        {--error-id= : Show detailed error log by ID (with full chain)}
        {--audits : Show ORM audit records (model changes)}
        {--audit-type= : Filter audits by auditable_type pattern}
        {--audit-event= : Filter by event (created, updated, deleted)}
        {--recent= : Show N most recent audit requests (no ID required)}
        {--recent-api-logs= : Show N most recent API logs globally (no ID required)}
        {--recent-jobs= : Show N most recent job dispatches globally (no ID required)}
        {--recent-errors= : Show N most recent errors globally (no ID required)}
        {--url= : Filter recent requests by URL pattern}
        {--user= : Filter by user ID}
        {--team= : Filter by team ID}
        {--session= : Filter by session ID}
        {--full : Show full content (disable truncation)}
        {--json : Output as JSON for programmatic use}';

    protected $description = 'Debug audit request data including API logs, jobs, errors, and model changes';

    public function handle(): int
    {
        $json = (bool)$this->option('json');
        $full = (bool)$this->option('full');

        // 1. Handle --recent modes (no audit request ID required)
        if ($limit = $this->option('recent')) {
            return $this->handleRecentRequests((int)$limit, $json);
        }
        if ($limit = $this->option('recent-api-logs')) {
            return $this->handleRecentApiLogs((int)$limit, $json);
        }
        if ($limit = $this->option('recent-jobs')) {
            return $this->handleRecentJobs((int)$limit, $json);
        }
        if ($limit = $this->option('recent-errors')) {
            return $this->handleRecentErrors((int)$limit, $json);
        }

        // 2. Handle direct ID lookups (no audit request required)
        if ($apiLogId = $this->option('api-id')) {
            return $this->handleApiLogDetail((int)$apiLogId, $full);
        }
        if ($jobId = $this->option('job-id')) {
            return $this->handleJobDispatchDetail((int)$jobId, $full);
        }
        if ($errorId = $this->option('error-id')) {
            return $this->handleErrorLogDetail((int)$errorId, $full);
        }

        // 3. Require audit request ID for remaining options
        $auditRequestId = $this->argument('audit-request');
        if (!$auditRequestId) {
            $this->showUsageHelp();

            return 1;
        }

        // 4. Find audit request
        $auditRequest = app(AuditDebugService::class)->findAuditRequest((int)$auditRequestId, $this);
        if (!$auditRequest) {
            return 1;
        }

        // 5. Route to appropriate handler based on options
        if ($this->option('logs')) {
            return $this->handleRequestLogs($auditRequest, $full);
        }
        if ($this->option('api-logs')) {
            return $this->handleApiLogs($auditRequest, $json);
        }
        if ($this->option('jobs')) {
            return $this->handleJobDispatches($auditRequest, $json);
        }
        if ($this->option('errors')) {
            return $this->handleErrors($auditRequest, $json);
        }
        if ($this->option('audits')) {
            return $this->handleAuditRecords($auditRequest, $json);
        }

        // 6. Default: show overview
        return $this->handleOverview($auditRequest, $json);
    }

    /**
     * Handle --recent mode: list most recent audit requests.
     */
    private function handleRecentRequests(int $limit, bool $json): int
    {
        $filters = $this->getRecentFilters();
        app(AuditDebugService::class)->listRecentRequests($limit, $filters, $this, $json);

        return 0;
    }

    /**
     * Handle --recent-api-logs mode: list most recent API logs globally.
     */
    private function handleRecentApiLogs(int $limit, bool $json): int
    {
        $filters = $this->getApiLogFilters();
        app(ApiLogDebugService::class)->listRecentApiLogs($limit, $filters, $this, $json);

        return 0;
    }

    /**
     * Handle --recent-jobs mode: list most recent job dispatches globally.
     */
    private function handleRecentJobs(int $limit, bool $json): int
    {
        $filters = $this->getJobFilters();
        app(JobDispatchDebugService::class)->listRecentJobDispatches($limit, $filters, $this, $json);

        return 0;
    }

    /**
     * Handle --recent-errors mode: list most recent errors globally.
     */
    private function handleRecentErrors(int $limit, bool $json): int
    {
        $filters = $this->getErrorFilters();
        app(ErrorLogDebugService::class)->listRecentErrors($limit, $filters, $this, $json);

        return 0;
    }

    /**
     * Handle --api-id option: show detailed API log.
     */
    private function handleApiLogDetail(int $id, bool $full): int
    {
        app(ApiLogDebugService::class)->showApiLogDetail($id, $this, $full);

        return 0;
    }

    /**
     * Handle --job-id option: show detailed job dispatch.
     */
    private function handleJobDispatchDetail(int $id, bool $full): int
    {
        app(JobDispatchDebugService::class)->showJobDispatchDetail($id, $this, $full);

        return 0;
    }

    /**
     * Handle --error-id option: show detailed error log with chain.
     */
    private function handleErrorLogDetail(int $id, bool $full): int
    {
        app(ErrorLogDebugService::class)->showErrorLogDetail($id, $this, $full);

        return 0;
    }

    /**
     * Handle --logs option: show server logs from the request.
     */
    private function handleRequestLogs(AuditRequest $auditRequest, bool $full): int
    {
        app(AuditDebugService::class)->showRequestLogs($auditRequest, $this, $full);

        return 0;
    }

    /**
     * Handle --api-logs option: list API logs for the request.
     */
    private function handleApiLogs(AuditRequest $auditRequest, bool $json): int
    {
        $filters = $this->getApiLogFilters();
        app(ApiLogDebugService::class)->listApiLogs($auditRequest, $filters, $this, $json);

        return 0;
    }

    /**
     * Handle --jobs option: list job dispatches for the request.
     */
    private function handleJobDispatches(AuditRequest $auditRequest, bool $json): int
    {
        $filters = $this->getJobFilters();
        app(JobDispatchDebugService::class)->listJobDispatches($auditRequest, $filters, $this, $json);

        return 0;
    }

    /**
     * Handle --errors option: list error log entries for the request.
     */
    private function handleErrors(AuditRequest $auditRequest, bool $json): int
    {
        $filters = $this->getErrorFilters();
        app(ErrorLogDebugService::class)->listErrorLogEntries($auditRequest, $filters, $this, $json);

        return 0;
    }

    /**
     * Handle --audits option: list ORM audit records for the request.
     */
    private function handleAuditRecords(AuditRequest $auditRequest, bool $json): int
    {
        $filters = $this->getAuditFilters();
        app(AuditRecordDebugService::class)->listAuditRecords($auditRequest, $filters, $this, $json);

        return 0;
    }

    /**
     * Handle overview display (default when ID provided).
     */
    private function handleOverview(AuditRequest $auditRequest, bool $json): int
    {
        app(AuditDebugService::class)->showOverview($auditRequest, $this, $json);

        return 0;
    }

    /**
     * Build filter array for recent requests.
     */
    private function getRecentFilters(): array
    {
        return array_filter([
            'url'     => $this->option('url'),
            'user'    => $this->option('user') ? (int)$this->option('user') : null,
            'team'    => $this->option('team') ? (int)$this->option('team') : null,
            'session' => $this->option('session'),
        ]);
    }

    /**
     * Build filter array for API logs.
     */
    private function getApiLogFilters(): array
    {
        return array_filter([
            'service' => $this->option('api-service'),
            'class'   => $this->option('api-class'),
            'status'  => $this->option('api-status'),
            'slow'    => $this->option('api-slow') ? (int)$this->option('api-slow') : null,
        ]);
    }

    /**
     * Build filter array for job dispatches.
     */
    private function getJobFilters(): array
    {
        return array_filter([
            'status' => $this->option('job-status'),
            'name'   => $this->option('job-name'),
        ]);
    }

    /**
     * Build filter array for error logs.
     */
    private function getErrorFilters(): array
    {
        return array_filter([
            'level' => $this->option('error-level'),
            'class' => $this->option('error-class'),
        ]);
    }

    /**
     * Build filter array for audit records.
     */
    private function getAuditFilters(): array
    {
        return array_filter([
            'type'  => $this->option('audit-type'),
            'event' => $this->option('audit-event'),
        ]);
    }

    /**
     * Display usage help when no arguments provided.
     */
    private function showUsageHelp(): void
    {
        $this->line('');
        $this->info('USAGE:');
        $this->line('  audit:debug [audit-request-id] [options]');
        $this->line('');
        $this->info('EXAMPLES:');
        $this->line('  # Show overview of audit request 12345');
        $this->line('  php artisan audit:debug 12345');
        $this->line('');
        $this->line('  # Show recent 10 requests');
        $this->line('  php artisan audit:debug --recent=10');
        $this->line('');
        $this->line('  # Show recent requests to specific URL');
        $this->line('  php artisan audit:debug --recent=20 --url="/api/tasks"');
        $this->line('');
        $this->line('  # Show recent 10 API logs globally');
        $this->line('  php artisan audit:debug --recent-api-logs=10');
        $this->line('');
        $this->line('  # Show recent 10 job dispatches globally');
        $this->line('  php artisan audit:debug --recent-jobs=10');
        $this->line('');
        $this->line('  # Show recent 10 errors globally');
        $this->line('  php artisan audit:debug --recent-errors=10');
        $this->line('');
        $this->line('  # Show API logs for a request');
        $this->line('  php artisan audit:debug 12345 --api-logs');
        $this->line('');
        $this->line('  # Show only failed API calls (status >= 400)');
        $this->line('  php artisan audit:debug 12345 --api-logs --api-status=">=400"');
        $this->line('');
        $this->line('  # Show slow API calls (> 5 seconds)');
        $this->line('  php artisan audit:debug 12345 --api-logs --api-slow=5000');
        $this->line('');
        $this->line('  # Show detailed API log');
        $this->line('  php artisan audit:debug --api-id=67890');
        $this->line('');
        $this->line('  # Show job dispatches for a request');
        $this->line('  php artisan audit:debug 12345 --jobs');
        $this->line('');
        $this->line('  # Show only failed jobs');
        $this->line('  php artisan audit:debug 12345 --jobs --job-status=Failed');
        $this->line('');
        $this->line('  # Show error entries for a request');
        $this->line('  php artisan audit:debug 12345 --errors');
        $this->line('');
        $this->line('  # Show only CRITICAL+ errors');
        $this->line('  php artisan audit:debug 12345 --errors --error-level=CRITICAL');
        $this->line('');
        $this->line('  # Show the server logs captured during request');
        $this->line('  php artisan audit:debug 12345 --logs');
        $this->line('');
        $this->line('  # Show model changes made during request');
        $this->line('  php artisan audit:debug 12345 --audits');
        $this->line('');
        $this->line('  # Output as JSON for scripting');
        $this->line('  php artisan audit:debug 12345 --overview --json');
        $this->line('');
    }
}
