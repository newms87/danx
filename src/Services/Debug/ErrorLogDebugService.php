<?php

namespace Newms87\Danx\Services\Debug;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Models\Audit\ErrorLog;
use Newms87\Danx\Models\Audit\ErrorLogEntry;
use Newms87\Danx\Services\Debug\Concerns\DebugOutputHelper;

/**
 * Handles error log listing, detail views, and error chain visualization for debugging.
 */
class ErrorLogDebugService
{
    use DebugOutputHelper;

    private const int DEFAULT_MESSAGE_TRUNCATE = 100;

    private const int DEFAULT_STACK_TRACE_FRAMES = 20;

    /**
     * List error log entries for an audit request with optional filtering.
     *
     * Filters supported:
     * - 'level' => minimum level (e.g., "ERROR", "WARNING")
     * - 'class' => LIKE match on errorLog.error_class
     */
    public function listErrorLogEntries(AuditRequest $auditRequest, array $filters, Command $command, bool $json = false): void
    {
        $query = $auditRequest->errorLogEntries()->with('errorLog');

        // Apply filters
        if (!empty($filters['level'])) {
            $minLevel = ErrorLog::getLevelInt(strtoupper($filters['level']));
            $query->whereHas('errorLog', fn($q) => $q->where('level', '>=', $minLevel));
        }

        if (!empty($filters['class'])) {
            $query->whereHas('errorLog', fn($q) => $q->where('error_class', 'LIKE', '%' . $filters['class'] . '%'));
        }

        $entries = $query->orderBy('created_at')->get();

        if ($json) {
            $this->outputJsonList($entries, $command);

            return;
        }

        $this->showHeader("Errors for Audit Request #{$auditRequest->id}", $command);

        if ($entries->isEmpty()) {
            $command->line('No error logs found matching the filters.');

            return;
        }

        // Group entries by their errorLog to avoid duplicates
        $groupedByErrorLog = $entries->groupBy('error_log_id');
        $this->renderErrorLogTable($groupedByErrorLog, $command);

        $command->newLine();
        $command->comment('Use --error-id=ID for full stack trace and error chain');
    }

    /**
     * List most recent errors globally (not tied to a specific audit request).
     *
     * Filters supported:
     * - 'level' => minimum level (e.g., "ERROR", "WARNING")
     * - 'class' => LIKE match on error_class
     */
    public function listRecentErrors(int $limit, array $filters, Command $command, bool $json = false): void
    {
        $query = ErrorLog::query()->orderByDesc('last_seen_at');

        // Apply filters
        if (!empty($filters['level'])) {
            $minLevel = ErrorLog::getLevelInt(strtoupper($filters['level']));
            $query->where('level', '>=', $minLevel);
        }

        if (!empty($filters['class'])) {
            $query->where('error_class', 'LIKE', '%' . $filters['class'] . '%');
        }

        $errorLogs = $query->limit($limit)->get();

        if ($json) {
            $this->outputRecentErrorsJson($errorLogs, $command);

            return;
        }

        $this->showHeader('Recent Errors', $command);

        if ($errorLogs->isEmpty()) {
            $command->line('No errors found matching the filters.');

            return;
        }

        $this->renderRecentErrorsTable($errorLogs, $command);

        $command->newLine();
        $command->comment('Use --error-id=ID for full stack trace and error chain');
    }

    /**
     * Show detailed information for a specific error log.
     */
    public function showErrorLogDetail(int $errorLogId, Command $command, bool $full = false): void
    {
        $errorLog = ErrorLog::with(['parent', 'children'])->find($errorLogId);

        if (!$errorLog) {
            $command->error("Error Log #{$errorLogId} not found.");

            return;
        }

        $maxFrames = $full ? PHP_INT_MAX : self::DEFAULT_STACK_TRACE_FRAMES;

        $this->showHeader("Error Log #{$errorLog->id}", $command);

        // Level
        $levelName = $this->safeLevelName((int)$errorLog->level);
        $command->line('Level: ' . $this->colorizeLevelName($levelName));

        // Class
        $command->line("Class: {$errorLog->error_class}");

        // Code
        $command->line("Code: {$errorLog->code}");

        // Message
        $command->line("Message: {$errorLog->message}");

        // Location
        if ($errorLog->file) {
            $command->line("Location: {$errorLog->file}:{$errorLog->line}");
        }

        $command->newLine();

        // Statistics
        $this->showSubHeader('Statistics', $command);
        $command->line("  Total occurrences: {$errorLog->count}");
        $command->line("  First seen: {$this->formatTimestamp($errorLog->created_at)}");
        $command->line("  Last seen: {$this->formatTimestamp($errorLog->last_seen_at)}");
        $notificationStatus = $errorLog->send_notifications ? 'Enabled' : 'Disabled';
        $command->line("  Notifications: {$notificationStatus}");

        // Stack Trace
        if ($errorLog->stack_trace) {
            $command->newLine();
            $this->showSubHeader('Stack Trace', $command);
            $formattedTrace = $this->formatStackTrace($errorLog->stack_trace, $maxFrames);
            $command->line($formattedTrace);
        }

        // Error Chain
        if ($errorLog->parent_id || $errorLog->children->isNotEmpty()) {
            $command->newLine();
            $this->showHeader('Error Chain', $command);
            $this->showErrorChain($errorLog, $command);
        }
    }

    /**
     * Show the full error chain (previous exceptions) for an error log.
     */
    public function showErrorChain(ErrorLog $errorLog, Command $command): void
    {
        // Find the root of the chain
        $root = $errorLog->root_id ? ErrorLog::with('children')->find($errorLog->root_id) : $errorLog;

        if (!$root) {
            $root = $errorLog;
        }

        // Display the chain hierarchically starting from root
        $this->displayChainNode($root, $errorLog, $command, 0);
    }

    /**
     * Format stack trace array into readable output.
     */
    public function formatStackTrace(array $trace, int $maxFrames = 20): string
    {
        $output = [];
        $frames = array_slice($trace, 0, $maxFrames);

        foreach ($frames as $index => $frame) {
            $file     = $frame['file']     ?? '[internal]';
            $line     = $frame['line']     ?? '?';
            $class    = $frame['class']    ?? '';
            $function = $frame['function'] ?? '?';

            $caller   = $class ? "{$class}::{$function}()" : "{$function}()";
            $output[] = "  #{$index} {$file}:{$line} - {$caller}";
        }

        if (count($trace) > $maxFrames) {
            $remaining = count($trace) - $maxFrames;
            $output[]  = "  + {$remaining} more frames";
        }

        return implode("\n", $output);
    }

    /**
     * Render recent errors as a table.
     */
    private function renderRecentErrorsTable(Collection $errorLogs, Command $command): void
    {
        $rows = $errorLogs->map(function (ErrorLog $errorLog) {
            $levelName = $this->safeLevelName((int)$errorLog->level);
            $colorized = $this->colorizeLevelName($levelName);
            $message   = $this->truncate($errorLog->message ?? '', self::DEFAULT_MESSAGE_TRUNCATE);
            $location  = $errorLog->file ? basename($errorLog->file) . ":{$errorLog->line}" : '-';
            $hasChain  = $errorLog->parent_id || ErrorLog::where('parent_id', $errorLog->id)->exists();

            return [
                $errorLog->id,
                "{$colorized} {$errorLog->error_class}",
                $message,
                $location,
                $errorLog->count,
                $this->formatTimestamp($errorLog->last_seen_at),
                $hasChain ? 'Yes' : 'No',
            ];
        })->toArray();

        $command->table(
            ['ID', 'Level / Class', 'Message', 'Location', 'Count', 'Last Seen', 'Has Chain'],
            $rows
        );
    }

    /**
     * Output recent errors as JSON.
     */
    private function outputRecentErrorsJson(Collection $errorLogs, Command $command): void
    {
        $data = $errorLogs->map(function (ErrorLog $errorLog) {
            $hasChain = $errorLog->parent_id || ErrorLog::where('parent_id', $errorLog->id)->exists();

            return [
                'id'           => $errorLog->id,
                'level'        => $this->safeLevelName((int)$errorLog->level),
                'level_int'    => $errorLog->level,
                'error_class'  => $errorLog->error_class,
                'code'         => $errorLog->code,
                'message'      => $errorLog->message,
                'file'         => $errorLog->file,
                'line'         => $errorLog->line,
                'count'        => $errorLog->count,
                'has_chain'    => $hasChain,
                'last_seen_at' => $errorLog->last_seen_at?->toIso8601String(),
                'created_at'   => $errorLog->created_at?->toIso8601String(),
            ];
        })->toArray();

        $command->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Render grouped error logs as a table.
     */
    private function renderErrorLogTable(Collection $groupedByErrorLog, Command $command): void
    {
        $rows = [];

        foreach ($groupedByErrorLog as $errorLogId => $entries) {
            /** @var ErrorLogEntry $firstEntry */
            $firstEntry = $entries->first();
            $errorLog   = $firstEntry->errorLog;

            if (!$errorLog) {
                continue;
            }

            $levelName    = $this->safeLevelName((int)$errorLog->level);
            $colorized    = $this->colorizeLevelName($levelName);
            $message      = $this->truncate($errorLog->message ?? '', self::DEFAULT_MESSAGE_TRUNCATE);
            $location     = $errorLog->file ? basename($errorLog->file) . ":{$errorLog->line}" : '-';
            $hasChain     = $errorLog->parent_id || ErrorLog::where('parent_id', $errorLog->id)->exists();
            $isRetryable  = $entries->contains('is_retryable', true);

            $rows[] = [
                $errorLog->id,
                "{$colorized} {$errorLog->error_class}",
                $message,
                $location,
                "Count: {$errorLog->count} | Retryable: " . ($isRetryable ? 'Yes' : 'No'),
                $hasChain ? 'Yes' : 'No',
            ];
        }

        $command->table(
            ['ID', 'Level / Class', 'Message', 'Location', 'Stats', 'Has Chain'],
            $rows
        );
    }

    /**
     * Display a node in the error chain recursively.
     */
    private function displayChainNode(ErrorLog $node, ErrorLog $current, Command $command, int $depth): void
    {
        $indent  = str_repeat('  ', $depth);
        $prefix  = $depth    === 0 ? '[ROOT]' : '[CHILD]';
        $marker  = $node->id === $current->id ? ' (<fg=cyan>current</>)' : '';
        $message = $this->truncate($node->message ?? '', 60);

        if ($depth > 0) {
            $command->line("{$indent}<fg=gray>\u{21B3}</> {$prefix} {$node->error_class}: {$message}{$marker}");
        } else {
            $command->line("{$indent}{$prefix} {$node->error_class}: {$message}{$marker}");
        }

        // Load and display children
        $children = ErrorLog::where('parent_id', $node->id)->get();

        foreach ($children as $child) {
            $this->displayChainNode($child, $current, $command, $depth + 1);
        }
    }

    /**
     * Safely get level name with fallback for unknown levels.
     */
    private function safeLevelName(int $level): string
    {
        return match ($level) {
            ErrorLog::DEBUG     => 'DEBUG',
            ErrorLog::INFO      => 'INFO',
            ErrorLog::NOTICE    => 'NOTICE',
            ErrorLog::WARNING   => 'WARNING',
            ErrorLog::ERROR     => 'ERROR',
            ErrorLog::CRITICAL  => 'CRITICAL',
            ErrorLog::ALERT     => 'ALERT',
            ErrorLog::EMERGENCY => 'EMERGENCY',
            default             => "LEVEL_{$level}",
        };
    }

    /**
     * Colorize a PSR-3 level name for terminal output.
     */
    private function colorizeLevelName(string $levelName): string
    {
        return match ($levelName) {
            'DEBUG', 'INFO' => "<fg=gray>{$levelName}</>",
            'NOTICE'        => "<fg=cyan>{$levelName}</>",
            'WARNING'       => "<fg=yellow>{$levelName}</>",
            'ERROR'         => "<fg=red>{$levelName}</>",
            'CRITICAL', 'ALERT', 'EMERGENCY' => "<fg=bright-red>{$levelName}</>",
            default         => $levelName,
        };
    }

    /**
     * Output error log entries as JSON.
     */
    private function outputJsonList(Collection $entries, Command $command): void
    {
        $groupedByErrorLog = $entries->groupBy('error_log_id');

        $data = $groupedByErrorLog->map(function ($entries) {
            /** @var ErrorLogEntry $firstEntry */
            $firstEntry = $entries->first();
            $errorLog   = $firstEntry->errorLog;

            if (!$errorLog) {
                return null;
            }

            $hasChain    = $errorLog->parent_id || ErrorLog::where('parent_id', $errorLog->id)->exists();
            $isRetryable = $entries->contains('is_retryable', true);

            return [
                'id'           => $errorLog->id,
                'level'        => $this->safeLevelName((int)$errorLog->level),
                'level_int'    => $errorLog->level,
                'error_class'  => $errorLog->error_class,
                'code'         => $errorLog->code,
                'message'      => $errorLog->message,
                'file'         => $errorLog->file,
                'line'         => $errorLog->line,
                'count'        => $errorLog->count,
                'is_retryable' => $isRetryable,
                'has_chain'    => $hasChain,
                'last_seen_at' => $errorLog->last_seen_at?->toIso8601String(),
                'created_at'   => $errorLog->created_at?->toIso8601String(),
            ];
        })->filter()->values()->toArray();

        $command->line(json_encode($data, JSON_PRETTY_PRINT));
    }
}
