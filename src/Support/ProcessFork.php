<?php

namespace Newms87\Danx\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Traits\HasDebugLogging;

/**
 * Forks child OS processes to run closures in parallel, like Promise.all() in JavaScript.
 *
 * Each closure runs in its own forked child process with an independent DB connection.
 * Results are passed back to the parent via temporary files (serialized PHP).
 * The parent waits for all children, collects results, and returns them.
 *
 * Follows the same DB disconnect/reconnect pattern as Heartbeat:
 * DB::disconnect() before fork, DB::reconnect() in both parent and child.
 *
 * Usage:
 *   $results = ProcessFork::run([
 *       fn() => expensiveLlmCall($batch1),
 *       fn() => expensiveLlmCall($batch2),
 *       fn() => expensiveLlmCall($batch3),
 *   ]);
 *   // $results = [['status' => 'success', 'result' => ...], ...]
 */
class ProcessFork
{
    use HasDebugLogging;

    /**
     * Run an array of closures in parallel child processes.
     *
     * Each closure receives no arguments and should return a serializable value.
     * Results are returned in the same order as the input tasks array.
     *
     * When $auditLabel is provided and auditing is enabled, each forked child gets its own
     * AuditRequest (parented to the current audit request) for isolated logging.
     * The child's audit_request_id is included in each result entry.
     *
     * When $shouldContinue is provided, the parent process polls it periodically while waiting
     * for children. If it returns false, all active children are sent SIGTERM and the method
     * returns with 'cancelled' error results for any unfinished tasks.
     *
     * @param  array<callable>  $tasks  Closures to execute in parallel
     * @param  int|null  $maxConcurrent  Max children to run simultaneously (null = all at once)
     * @param  string|null  $auditLabel  Label prefix for child audit requests (e.g., "IdentityExtraction")
     * @param  callable|null  $shouldContinue  Callback returning bool — false triggers cancellation of all children
     * @return array<array{status: string, result: mixed, error: string|null, audit_request_id: int|null}>
     */
    public static function run(array $tasks, ?int $maxConcurrent = null, ?string $auditLabel = null, ?callable $shouldContinue = null): array
    {
        if (empty($tasks)) {
            return [];
        }

        // Capture parent audit request ID before forking
        $parentAuditRequestId = $auditLabel ? AuditDriver::$auditRequest?->id : null;

        // If pcntl is not available, run sequentially as fallback
        if (!function_exists('pcntl_fork')) {
            self::logDebug('pcntl not available, running tasks sequentially');

            return self::runSequentially($tasks);
        }

        // If only one task, no point forking
        if (count($tasks) === 1) {
            return self::runSequentially($tasks);
        }

        $maxConcurrent = $maxConcurrent ?? count($tasks);
        $maxConcurrent = max(1, $maxConcurrent);

        return self::forkAndRun($tasks, $maxConcurrent, $parentAuditRequestId, $auditLabel, $shouldContinue);
    }

    /**
     * Run all tasks sequentially (fallback when pcntl unavailable or single task).
     *
     * @param  array<callable>  $tasks
     * @return array<array{status: string, result: mixed, error: string|null}>
     */
    protected static function runSequentially(array $tasks): array
    {
        $results = [];

        foreach ($tasks as $index => $task) {
            try {
                $results[$index] = self::successResult($task());
            } catch (\Throwable $e) {
                $results[$index] = self::errorResult($e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Build a success result entry.
     */
    protected static function successResult(mixed $result, ?int $auditRequestId = null): array
    {
        return ['status' => 'success', 'result' => $result, 'error' => null, 'audit_request_id' => $auditRequestId];
    }

    /**
     * Build an error result entry.
     */
    protected static function errorResult(string $error, ?int $auditRequestId = null): array
    {
        return ['status' => 'error', 'result' => null, 'error' => $error, 'audit_request_id' => $auditRequestId];
    }

    /**
     * Fork child processes to run tasks in parallel, respecting concurrency limits.
     *
     * When maxConcurrent < total tasks, forks in waves: starts N children, waits for
     * any to finish, then forks the next, until all tasks are dispatched and completed.
     *
     * When $shouldContinue is provided, uses non-blocking wait (WNOHANG) and polls the
     * callback every ~1 second. If it returns false, sends SIGTERM to all active children,
     * waits for them to exit, and returns 'cancelled' results for unfinished tasks.
     *
     * @param  array<callable>  $tasks
     * @param  callable|null  $shouldContinue  Polled periodically — return false to cancel all children
     * @return array<array{status: string, result: mixed, error: string|null}>
     */
    protected static function forkAndRun(array $tasks, int $maxConcurrent, ?int $parentAuditRequestId = null, ?string $auditLabel = null, ?callable $shouldContinue = null): array
    {
        // Normalize to 0-indexed array — callers may pass string-keyed arrays (e.g., "page_1", "artifact_5")
        $tasks     = array_values($tasks);
        $taskCount = count($tasks);
        $results   = array_fill(0, $taskCount, ['status' => 'error', 'result' => null, 'error' => 'Not started', 'audit_request_id' => null]);

        // Create temp files for each child to write results
        $tempFiles = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $tempFiles[$i] = tempnam(sys_get_temp_dir(), 'pfork_');
        }

        // Track active children: pid => taskIndex
        $activeChildren = [];
        $nextTaskIndex  = 0;
        $cancelled      = false;

        self::logDebug("Forking $taskCount tasks with max concurrency $maxConcurrent");

        // Fork tasks in waves
        while ($nextTaskIndex < $taskCount || !empty($activeChildren)) {
            // Check cancellation before forking new children
            if (!$cancelled && $shouldContinue && !$shouldContinue()) {
                self::logDebug('shouldContinue returned false — cancelling all children');
                self::killActiveChildren($activeChildren);
                $cancelled = true;
                // Don't fork any more tasks — just wait for children to exit below
            }

            // Fork new children up to the concurrency limit (skip if cancelled)
            while (!$cancelled && $nextTaskIndex < $taskCount && count($activeChildren) < $maxConcurrent) {
                $taskIndex = $nextTaskIndex++;
                $task      = $tasks[$taskIndex];
                $tempFile  = $tempFiles[$taskIndex];

                $childAuditLabel = $auditLabel ? "[fork] $auditLabel:batch-$taskIndex" : null;
                $pid             = self::forkChild($task, $tempFile, $parentAuditRequestId, $childAuditLabel);

                if ($pid === null) {
                    // Fork failed — record error, continue with remaining tasks
                    $results[$taskIndex] = self::errorResult('Fork failed');
                    @unlink($tempFile);
                } else {
                    $activeChildren[$pid] = $taskIndex;
                }
            }

            // If cancelled, mark remaining un-forked tasks
            if ($cancelled && $nextTaskIndex < $taskCount) {
                while ($nextTaskIndex < $taskCount) {
                    $results[$nextTaskIndex] = self::errorResult('Cancelled');
                    @unlink($tempFiles[$nextTaskIndex]);
                    $nextTaskIndex++;
                }
            }

            // Wait for any child to finish
            if (!empty($activeChildren)) {
                if ($shouldContinue) {
                    // Non-blocking wait — poll shouldContinue between checks
                    $exitedPid = self::waitForChildNonBlocking($activeChildren, $results, $tempFiles, $shouldContinue, $cancelled);

                    if ($exitedPid === -2) {
                        // Cancellation triggered during wait
                        $cancelled = true;
                    }
                } else {
                    // Blocking wait — no cancellation callback
                    $status    = 0;
                    $exitedPid = pcntl_waitpid(-1, $status);

                    if ($exitedPid > 0 && isset($activeChildren[$exitedPid])) {
                        $taskIndex = $activeChildren[$exitedPid];
                        unset($activeChildren[$exitedPid]);
                        $results[$taskIndex] = self::readChildResult($tempFiles[$taskIndex], $status);
                    }
                }
            }
        }

        // Clean up temp files
        foreach ($tempFiles as $tempFile) {
            @unlink($tempFile);
        }

        self::logDebug('All forked tasks completed', [
            'total'     => $taskCount,
            'succeeded' => count(array_filter($results, fn($r) => $r['status'] === 'success')),
            'failed'    => count(array_filter($results, fn($r) => $r['status'] === 'error')),
        ]);

        return $results;
    }

    /**
     * Non-blocking wait loop that polls shouldContinue every ~1 second.
     * Reaps any exited children and records their results. If shouldContinue returns false,
     * kills all remaining children and returns -2 to signal cancellation.
     *
     * @return int  -2 if cancelled, otherwise the last reaped PID (or 0 if none reaped yet)
     */
    protected static function waitForChildNonBlocking(array &$activeChildren, array &$results, array $tempFiles, callable $shouldContinue, bool $alreadyCancelled): int
    {
        while (!empty($activeChildren)) {
            // Try to reap any exited child (non-blocking)
            $status    = 0;
            $exitedPid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($exitedPid > 0 && isset($activeChildren[$exitedPid])) {
                $taskIndex = $activeChildren[$exitedPid];
                unset($activeChildren[$exitedPid]);
                $results[$taskIndex] = self::readChildResult($tempFiles[$taskIndex], $status);

                return $exitedPid;
            }

            // Check cancellation
            if (!$alreadyCancelled && !$shouldContinue()) {
                self::logDebug('shouldContinue returned false during wait — cancelling all children');
                self::killActiveChildren($activeChildren);

                // Wait for all killed children to actually exit
                self::reapKilledChildren($activeChildren, $results, $tempFiles);

                return -2;
            }

            // Sleep briefly to avoid busy-waiting
            usleep(500_000); // 0.5 seconds
        }

        return 0;
    }

    /**
     * Send SIGTERM to all active child processes.
     */
    protected static function killActiveChildren(array $activeChildren): void
    {
        foreach (array_keys($activeChildren) as $pid) {
            // Return value intentionally ignored — child may have already exited
            posix_kill($pid, SIGTERM);
        }
    }

    /**
     * Wait for all killed children to exit, recording their results as 'Cancelled'.
     */
    protected static function reapKilledChildren(array &$activeChildren, array &$results, array $tempFiles): void
    {
        while (!empty($activeChildren)) {
            $status    = 0;
            $exitedPid = pcntl_waitpid(-1, $status);

            if ($exitedPid > 0 && isset($activeChildren[$exitedPid])) {
                $taskIndex = $activeChildren[$exitedPid];
                unset($activeChildren[$exitedPid]);

                // Try to read the result — the child may have finished before SIGTERM arrived
                $result = self::readChildResult($tempFiles[$taskIndex], $status);
                if ($result['status'] === 'success') {
                    $results[$taskIndex] = $result;
                } else {
                    $results[$taskIndex] = self::errorResult('Cancelled');
                }
            }
        }
    }

    /**
     * Fork a single child process to execute a task.
     *
     * Follows Heartbeat pattern: DB::disconnect() → pcntl_fork() → DB::reconnect()
     * in both parent (on success) and child (before executing task).
     *
     * @return int|null  Child PID on success, null on fork failure
     */
    protected static function forkChild(callable $task, string $tempFile, ?int $parentAuditRequestId = null, ?string $auditLabel = null): ?int
    {
        DB::disconnect();
        self::purgeAllRedisConnections();

        $pid = pcntl_fork();

        if ($pid === -1) {
            // Fork failed — reconnect in parent and return null
            DB::reconnect();
            self::logWarning('pcntl_fork() failed');

            return null;
        }

        if ($pid === 0) {
            // === CHILD PROCESS ===
            self::executeInChild($task, $tempFile, $parentAuditRequestId, $auditLabel);
            exit(0); // Always exit cleanly — never return to parent code path
        }

        // === PARENT PROCESS ===
        DB::reconnect();

        return $pid;
    }

    /**
     * Execute a task inside the child process and write the result to a temp file.
     *
     * Installs SIGTERM handler for clean shutdown. Reconnects DB before running the task.
     * When audit params are provided, creates a child AuditRequest so all logs, API logs,
     * and errors in this child are isolated from the parent and other children.
     * Serializes the result (or error) to a temp file for the parent to read.
     */
    protected static function executeInChild(callable $task, string $tempFile, ?int $parentAuditRequestId = null, ?string $auditLabel = null): void
    {
        // Install signal handler for clean shutdown (same as Heartbeat)
        pcntl_signal(SIGTERM, function () {
            exit(0);
        });
        pcntl_async_signals(true);

        // Fresh DB and Redis connections for this child (forked processes
        // must not share sockets with the parent — causes corruption)
        DB::reconnect();
        self::purgeAllRedisConnections();
        Cache::forgetDriver();

        // Create isolated audit request for this child process
        $childAuditRequestId = null;
        if ($parentAuditRequestId && $auditLabel) {
            AuditDriver::$auditRequest = null;
            AuditDriver::startTimer();
            $childAuditRequest   = AuditDriver::createChildAuditRequest($parentAuditRequestId, $auditLabel);
            $childAuditRequestId = $childAuditRequest?->id;
        }

        try {
            $data = self::successResult($task(), $childAuditRequestId);
        } catch (\Throwable $e) {
            $data = self::errorResult($e->getMessage(), $childAuditRequestId);
        }

        // Finalize the child audit request (record execution time)
        if ($childAuditRequestId) {
            AuditDriver::terminate();
        }

        // Write serialized result to temp file — exit non-zero on failure so parent detects it
        $written = file_put_contents($tempFile, serialize($data));
        if ($written === false) {
            exit(1);
        }
    }

    /**
     * Purge ALL Redis connections (default, cache, queue, etc.) before forking.
     * Redis::purge() without arguments only purges 'default', leaving other connections
     * (like 'cache') with shared sockets between parent and child — causing corruption.
     */
    public static function purgeAllRedisConnections(): void
    {
        // Disconnect all active connections to close their sockets
        foreach (Redis::connections() ?? [] as $connection) {
            $connection->disconnect();
        }

        // Purge named connections from the manager's pool
        Redis::purge('default');
        Redis::purge('cache');
    }

    /**
     * Read the result written by a child process from its temp file.
     *
     * If the child exited abnormally or the file is missing/corrupt, returns an error result.
     *
     * @param  string  $tempFile  Path to the child's result file
     * @param  int  $status  The pcntl_waitpid status value
     * @return array{status: string, result: mixed, error: string|null}
     */
    protected static function readChildResult(string $tempFile, int $status): array
    {
        // Check for abnormal exit
        $exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;

        if (!file_exists($tempFile) || filesize($tempFile) === 0) {
            return self::errorResult("Child process exited with code $exitCode but produced no result");
        }

        $serialized = file_get_contents($tempFile);
        $data       = @unserialize($serialized);

        if ($data === false && $serialized !== serialize(false)) {
            return self::errorResult("Failed to unserialize child result (exit code: $exitCode)");
        }

        return $data;
    }
}
