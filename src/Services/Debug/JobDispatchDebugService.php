<?php

namespace Newms87\Danx\Services\Debug;

use Illuminate\Console\Command;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Services\Debug\Concerns\DebugOutputHelper;

/**
 * Handles job dispatch listing and detail views for debugging.
 */
class JobDispatchDebugService
{
    use DebugOutputHelper;

    private const int DEFAULT_REF_TRUNCATE = 20;

    private const int DEFAULT_JSON_TRUNCATE = 2000;

    /**
     * List job dispatches for an audit request with optional filtering.
     *
     * Checks BOTH relationships: ranJobs (jobs that ran during this request)
     * AND dispatchedJobs (jobs dispatched by this request).
     *
     * Filters supported:
     * - 'status' => exact match on status (Pending, Running, Complete, Exception, Failed, Timeout, Aborted)
     * - 'name' => LIKE match on name
     */
    public function listJobDispatches(AuditRequest $auditRequest, array $filters, Command $command, bool $json = false): void
    {
        $ranJobs        = $this->getFilteredJobs($auditRequest->ranJobs(), $filters);
        $dispatchedJobs = $this->getFilteredJobs($auditRequest->dispatchedJobs(), $filters);

        if ($json) {
            $this->outputJsonList($ranJobs, $dispatchedJobs, $command);

            return;
        }

        $this->showHeader("Job Dispatches for Audit Request #{$auditRequest->id}", $command);

        // Jobs that ran during this request
        $this->showSubHeader('Jobs Ran During Request', $command);
        if ($ranJobs->isEmpty()) {
            $command->line('No jobs ran during this request matching the filters.');
        } else {
            $this->renderJobsTable($ranJobs, $command);
            $this->showJobsSummary($ranJobs, $command, 'ran');
        }

        $command->newLine();

        // Jobs dispatched by this request
        $this->showSubHeader('Jobs Dispatched By Request', $command);
        if ($dispatchedJobs->isEmpty()) {
            $command->line('No jobs dispatched by this request matching the filters.');
        } else {
            $this->renderJobsTable($dispatchedJobs, $command);
            $this->showJobsSummary($dispatchedJobs, $command, 'dispatched');
        }

        $command->newLine();
        $command->comment('Use --job-id=ID for full details');
    }

    /**
     * List most recent job dispatches globally (not tied to a specific audit request).
     *
     * Filters supported:
     * - 'status' => exact match on status
     * - 'name' => LIKE match on name
     */
    public function listRecentJobDispatches(int $limit, array $filters, Command $command, bool $json = false): void
    {
        $query = JobDispatch::query()->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['name'])) {
            $query->where('name', 'LIKE', '%' . $filters['name'] . '%');
        }

        $jobs = $query->limit($limit)->get();

        if ($json) {
            $command->line(json_encode($this->mapJobsToArray($jobs), JSON_PRETTY_PRINT));

            return;
        }

        $this->showHeader('Recent Job Dispatches', $command);

        if ($jobs->isEmpty()) {
            $command->line('No job dispatches found matching the filters.');

            return;
        }

        $this->renderRecentJobsTable($jobs, $command);
        $this->showJobsSummary($jobs, $command, 'recent');

        $command->newLine();
        $command->comment('Use --job-id=ID for full details, or audit:debug <AR ID> to see the audit request');
    }

    /**
     * Show detailed information for a specific job dispatch.
     */
    public function showJobDispatchDetail(int $jobId, Command $command, bool $full = false): void
    {
        $jobDispatch = JobDispatch::with(['user', 'team', 'runningAuditRequest', 'dispatchAuditRequest'])
            ->find($jobId);

        if (!$jobDispatch) {
            $command->error("Job Dispatch #{$jobId} not found.");

            return;
        }

        $maxLength = $full ? PHP_INT_MAX : self::DEFAULT_JSON_TRUNCATE;

        $this->showHeader("Job Dispatch #{$jobDispatch->id}", $command);

        // Basic info
        $command->line("Name: {$jobDispatch->name}");
        $command->line("Ref: {$jobDispatch->ref}");
        $command->line('Status: ' . $this->colorizeJobStatus($jobDispatch->status));
        $command->line("Count: {$jobDispatch->count}");

        $command->newLine();

        // Timing section
        $command->line('<fg=cyan>Timing:</>');
        $command->line("  Created: {$this->formatTimestamp($jobDispatch->created_at)}");
        $command->line('  Ran at: ' . ($jobDispatch->ran_at ? $this->formatTimestamp($jobDispatch->ran_at) : 'Not started'));
        $command->line('  Completed at: ' . $this->formatCompletedAt($jobDispatch));
        $command->line('  Timeout at: ' . ($jobDispatch->timeout_at ? $this->formatTimestamp($jobDispatch->timeout_at) : '-'));
        $command->line('  Duration: ' . ($jobDispatch->run_time_ms ? $this->formatDuration($jobDispatch->run_time_ms) : '-'));

        $command->newLine();

        // User info
        if ($jobDispatch->user) {
            $command->line("User: {$jobDispatch->user->email} (ID: {$jobDispatch->user_id})");
        } else {
            $command->line('User: -');
        }

        // Team info
        if ($jobDispatch->team) {
            $command->line("Team: {$jobDispatch->team->name} (ID: {$jobDispatch->team_id})");
        } else {
            $command->line('Team: -');
        }

        // Job Batch
        $command->line('Job Batch: ' . ($jobDispatch->job_batch_id ?? '-'));

        // Audit Request links
        $this->showAuditRequestLink($command, 'Running Audit Request', $jobDispatch->runningAuditRequest);
        $this->showAuditRequestLink($command, 'Dispatch Audit Request', $jobDispatch->dispatchAuditRequest);

        // Data section
        $command->newLine();
        $this->showSubHeader('Data', $command);
        if ($jobDispatch->data) {
            $this->showJsonContent($jobDispatch->data, $command, $maxLength);
        } else {
            $command->line('    No data');
        }
    }

    /**
     * Get filtered jobs from a relationship query.
     */
    private function getFilteredJobs($query, array $filters)
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['name'])) {
            $query->where('name', 'LIKE', '%' . $filters['name'] . '%');
        }

        return $query->orderBy('created_at')->get();
    }

    /**
     * Render job dispatches as a table.
     */
    private function renderJobsTable($jobs, Command $command): void
    {
        $rows = $jobs->map(fn(JobDispatch $job) => [
            $job->id,
            $job->name ?? '-',
            $this->truncate($job->ref ?? '', self::DEFAULT_REF_TRUNCATE),
            $this->colorizeJobStatus($job->status),
            $job->count,
            $job->run_time_ms ? $this->formatDuration($job->run_time_ms) : '-',
            $this->formatTimestamp($job->created_at),
        ])->toArray();

        $command->table(
            ['ID', 'Name', 'Ref', 'Status', 'Count', 'Duration', 'Created'],
            $rows
        );
    }

    /**
     * Render recent job dispatches as a table (includes Audit Request IDs).
     */
    private function renderRecentJobsTable($jobs, Command $command): void
    {
        $rows = $jobs->map(fn(JobDispatch $job) => [
            $job->id,
            $job->running_audit_request_id ?? '-',
            $job->name                     ?? '-',
            $this->colorizeJobStatus($job->status),
            $job->count,
            $job->run_time_ms ? $this->formatDuration($job->run_time_ms) : '-',
            $this->formatTimestamp($job->created_at),
        ])->toArray();

        $command->table(
            ['ID', 'AR ID', 'Name', 'Status', 'Count', 'Duration', 'Created'],
            $rows
        );
    }

    /**
     * Show summary statistics for job dispatches.
     */
    private function showJobsSummary($jobs, Command $command, string $type): void
    {
        $total      = $jobs->count();
        $completed  = $jobs->filter(fn(JobDispatch $job) => $job->status === JobDispatch::STATUS_COMPLETE)->count();
        $failed     = $jobs->filter(fn(JobDispatch $job) => in_array($job->status, [
            JobDispatch::STATUS_EXCEPTION,
            JobDispatch::STATUS_FAILED,
            JobDispatch::STATUS_TIMEOUT,
            JobDispatch::STATUS_ABORTED,
        ]))->count();
        $running    = $jobs->filter(fn(JobDispatch $job) => $job->status === JobDispatch::STATUS_RUNNING)->count();
        $pending    = $jobs->filter(fn(JobDispatch $job) => $job->status === JobDispatch::STATUS_PENDING)->count();

        $failedText = $failed > 0 ? "<fg=red>{$failed} failed</>" : '0 failed';
        $command->line("Total {$type}: {$total} jobs ({$completed} complete, {$running} running, {$pending} pending, {$failedText})");
    }

    /**
     * Format completed_at timestamp with context.
     */
    private function formatCompletedAt(JobDispatch $job): string
    {
        if ($job->completed_at) {
            return $this->formatTimestamp($job->completed_at);
        }

        if ($job->ran_at) {
            return 'Still running';
        }

        return 'Not started';
    }

    /**
     * Show an audit request link with URL hint.
     */
    private function showAuditRequestLink(Command $command, string $label, ?AuditRequest $auditRequest): void
    {
        if ($auditRequest) {
            $url = config('app.url') . "/api/audit-requests/{$auditRequest->id}";
            $command->line("{$label}: {$auditRequest->id} ({$url})");
        } else {
            $command->line("{$label}: -");
        }
    }

    /**
     * Output job dispatches as JSON.
     */
    private function outputJsonList($ranJobs, $dispatchedJobs, Command $command): void
    {
        $data = [
            'ran'        => $this->mapJobsToArray($ranJobs),
            'dispatched' => $this->mapJobsToArray($dispatchedJobs),
        ];

        $command->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Map job dispatches collection to array for JSON output.
     */
    private function mapJobsToArray($jobs): array
    {
        return $jobs->map(fn(JobDispatch $job) => [
            'id'           => $job->id,
            'name'         => $job->name,
            'ref'          => $job->ref,
            'status'       => $job->status,
            'count'        => $job->count,
            'run_time_ms'  => $job->run_time_ms,
            'ran_at'       => $job->ran_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'created_at'   => $job->created_at?->toIso8601String(),
        ])->toArray();
    }
}
