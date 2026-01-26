<?php

namespace Newms87\Danx\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Helpers\StringHelper;
use Newms87\Danx\Traits\HasDebugLogging;

/**
 * Handles signal registration and logging for CLI processes.
 *
 * This class registers handlers for common Unix signals (SIGTERM, SIGINT, etc.)
 * and logs when they are received. This is critical for debugging worker processes
 * that are killed externally (e.g., by Horizon, Lambda, or system limits).
 *
 * NOTE: SIGKILL (signal 9) cannot be caught - the process is killed immediately.
 *
 * Configuration:
 *   - Only active in CLI mode with pcntl extension loaded
 *   - Logs to STDERR (visible in CloudWatch on Lambda)
 *   - Logs to AuditRequest.logs if available (atomic DB update)
 *
 * Usage:
 *   - Automatically registered via DanxServiceProvider boot()
 *   - Call SignalHandler::setCurrentOperation() from jobs/tasks to track what's running
 */
class SignalHandler
{
    use HasDebugLogging;

    /**
     * Track what's currently running for signal debugging.
     * Set this from jobs/tasks to identify what was running when killed.
     */
    public static ?string $currentOperation = null;
    public static ?int $currentOperationStarted = null;

    /**
     * Set the current operation for signal debugging.
     * Call this at the start of long-running operations to track what was running if killed.
     */
    public static function setCurrentOperation(?string $operation): void
    {
        self::$currentOperation = $operation;
        self::$currentOperationStarted = $operation ? time() : null;
    }

    /**
     * Register signal handlers for CLI processes.
     * Should be called from DanxServiceProvider::boot().
     */
    public static function register(): void
    {
        // Only register in CLI mode with pcntl available
        if (!app()->runningInConsole() || !extension_loaded('pcntl')) {
            return;
        }

        $signals = [
            SIGTERM => 'SIGTERM',  // Graceful shutdown (Horizon uses this)
            SIGINT  => 'SIGINT',   // Ctrl+C
            SIGQUIT => 'SIGQUIT',  // Quit with core dump
            SIGHUP  => 'SIGHUP',   // Terminal hangup
            SIGALRM => 'SIGALRM',  // Alarm (Laravel uses for job timeouts)
        ];

        foreach ($signals as $signal => $name) {
            pcntl_signal($signal, fn() => self::handleSignal($name, $signal));
        }

        // Enable async signal handling so signals are processed immediately
        pcntl_async_signals(true);
    }

    /**
     * Handle a received signal by logging and then re-raising.
     */
    private static function handleSignal(string $name, int $signal): void
    {
        $pid = getmypid();
        $runningFor = self::$currentOperationStarted
            ? (time() - self::$currentOperationStarted) . 's'
            : 'unknown';

        $context = [
            'signal'            => $name,
            'signal_num'        => $signal,
            'pid'               => $pid,
            'memory_mb'         => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb'    => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'current_operation' => self::$currentOperation ?? 'none',
            'running_for'       => $runningFor,
        ];

        $message = sprintf(
            'SIGNAL RECEIVED: Process %d received %s | Operation: %s | Running: %s | Memory: %sMB',
            $pid,
            $name,
            self::$currentOperation ?? 'none',
            $runningFor,
            $context['memory_mb']
        );

        // Log with high priority via Laravel's logger
        Log::warning($message, $context);

        // Write to STDERR for Lambda CloudWatch visibility
        self::writeToStderr($message, $context);

        // Try to log to AuditRequest if available
        self::writeToAuditRequest($message);

        // Re-raise the signal so normal handling continues
        pcntl_signal($signal, SIG_DFL);
        posix_kill($pid, $signal);
    }

    /**
     * Write signal log to STDERR for Lambda CloudWatch visibility.
     * This ensures logs are captured even if the process dies immediately.
     */
    private static function writeToStderr(string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES);
        $logLine = "[{$timestamp}] SIGNAL: {$message} | Context: {$contextJson}\n";

        fwrite(STDERR, $logLine);
    }

    /**
     * Write signal log to AuditRequest.logs using atomic DB update.
     * This persists the signal info even if the process dies.
     */
    private static function writeToAuditRequest(string $message): void
    {
        $auditRequest = AuditDriver::$auditRequest;

        if (!$auditRequest?->id) {
            return;
        }

        try {
            $timestamp = now()->toDateTimeString();
            $entry = "\n{$timestamp} SIGNAL {$message}";
            $entry = StringHelper::logSafeString($entry, 100000);

            // Use atomic SQL concatenation - same pattern as AuditLogHandler
            DB::statement(
                "UPDATE audit_request SET logs = COALESCE(logs, '') || ?, log_line_count = COALESCE(log_line_count, 0) + ? WHERE id = ?",
                [$entry, substr_count($entry, "\n"), $auditRequest->id]
            );
        } catch (\Throwable) {
            // Silently ignore DB errors - we're in a signal handler and may be dying
        }
    }
}
