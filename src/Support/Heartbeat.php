<?php

namespace Newms87\Danx\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Models\Job\JobDispatch;
use Newms87\Danx\Traits\HasDebugLogging;

/**
 * Forks a child process that logs heartbeats to the audit_request.logs field
 * while a long-running operation executes.
 *
 * If the parent process is killed (even with SIGKILL), the child will detect it
 * and log that the parent died unexpectedly.
 *
 * Configuration (in .env):
 *   JOB_HEARTBEAT_ENABLED=true      Enable/disable heartbeat (default: true)
 *   JOB_HEARTBEAT_INTERVAL=10       Seconds between heartbeats (default: 10)
 *
 * Usage:
 *   $heartbeat = Heartbeat::start('MyOperation');
 *   try {
 *       // long-running operation
 *   } finally {
 *       $heartbeat->stop();
 *   }
 */
class Heartbeat
{
    use HasDebugLogging;

    private int $childPid = 0;
    private string $operationId;
    private bool $stopped = false;
    private ?int $jobDispatchId = null;

    private function __construct(string $operationId, ?int $jobDispatchId = null)
    {
        $this->operationId = $operationId;
        $this->jobDispatchId = $jobDispatchId;
    }

    /**
     * Start a heartbeat logger for an operation.
     *
     * @param string   $operationId     Identifier for the operation being monitored
     * @param int|null $intervalSeconds Override interval (uses config default if null)
     * @param int|null $jobDispatchId   JobDispatch ID to mark as timeout if parent dies
     * @return self
     */
    public static function start(string $operationId, ?int $intervalSeconds = null, ?int $jobDispatchId = null): self
    {
        $instance = new self($operationId, $jobDispatchId);

        if (!$instance->isEnabled()) {
            self::logDebug("Heartbeat DISABLED via config for {$operationId}");
            return $instance;
        }

        if (!$instance->canFork()) {
            return $instance;
        }

        $auditRequestId = AuditDriver::$auditRequest?->id;

        if (!$auditRequestId) {
            self::logDebug("Heartbeat: No audit request available for {$operationId}");
            return $instance;
        }

        $intervalSeconds = $intervalSeconds ?? $instance->getIntervalSeconds();

        return $instance->forkHeartbeatProcess($auditRequestId, $intervalSeconds);
    }

    /**
     * Stop the heartbeat logger.
     * Call this when the API request completes (success or failure).
     */
    public function stop(): void
    {
        if ($this->stopped || $this->childPid === 0) {
            return;
        }

        $this->stopped = true;

        if (function_exists('posix_kill')) {
            posix_kill($this->childPid, SIGTERM);
            pcntl_waitpid($this->childPid, $status, WNOHANG);
        }
    }

    /**
     * Check if heartbeat is enabled via configuration.
     */
    private function isEnabled(): bool
    {
        return (bool) config('danx.audit.heartbeat.enabled', true);
    }

    /**
     * Check if pcntl_fork is available for forking processes.
     */
    private function canFork(): bool
    {
        if (!function_exists('pcntl_fork')) {
            self::logDebug("pcntl not available, skipping heartbeat for {$this->operationId}");
            return false;
        }

        return true;
    }

    /**
     * Get the heartbeat interval in seconds from configuration.
     */
    private function getIntervalSeconds(): int
    {
        return (int) config('danx.audit.heartbeat.interval', 10);
    }

    /**
     * Fork a child process to run the heartbeat loop.
     */
    private function forkHeartbeatProcess(int $auditRequestId, int $intervalSeconds): self
    {
        $parentPid = getmypid();
        $startTime = time();

        self::logDebug("Forking heartbeat process (audit_request_id={$auditRequestId}, interval={$intervalSeconds}s, job_dispatch_id={$this->jobDispatchId})");

        DB::disconnect();
        ProcessFork::purgeAllRedisConnections();

        $pid = pcntl_fork();

        if ($pid === -1) {
            DB::reconnect();
            self::logWarning("Fork failed for {$this->operationId}");
            return $this;
        }

        if ($pid === 0) {
            $this->runHeartbeatLoop($parentPid, $this->operationId, $intervalSeconds, $startTime, $auditRequestId, $this->jobDispatchId);
            exit(0);
        }

        DB::reconnect();
        $this->childPid = $pid;
        self::logDebug("Forked heartbeat child PID: {$pid}");

        return $this;
    }

    /**
     * Run the heartbeat loop in the child process.
     * Attaches to the parent's AuditRequest and uses standard debug logging.
     * If the parent dies unexpectedly, marks the JobDispatch as timed out.
     */
    private function runHeartbeatLoop(
        int $parentPid,
        string $operationId,
        int $intervalSeconds,
        int $startTime,
        int $auditRequestId,
        ?int $jobDispatchId = null
    ): void {
        pcntl_signal(SIGTERM, function () {
            exit(0);
        });
        pcntl_async_signals(true);

        // Fresh DB and Redis connections for this child (forked processes
        // must not share sockets with the parent — causes corruption)
        DB::reconnect();
        ProcessFork::purgeAllRedisConnections();
        Cache::forgetDriver();

        $auditRequest = AuditRequest::find($auditRequestId);
        if ($auditRequest) {
            AuditDriver::$auditRequest = $auditRequest;
        }

        $heartbeatCount = 0;

        while (true) {
            sleep($intervalSeconds);
            $heartbeatCount++;
            $elapsed = time() - $startTime;
            $memoryMb = round(memory_get_usage(true) / 1024 / 1024, 2);

            $currentParentPid = posix_getppid();

            if ($currentParentPid !== $parentPid) {
                self::logError("PARENT_DIED #{$heartbeatCount} | {$operationId} | PID:{$parentPid} | {$elapsed}s | {$memoryMb}MB | Parent PID changed to {$currentParentPid}");

                // Mark the JobDispatch as timed out so the task process can be restarted
                if ($jobDispatchId) {
                    $this->markJobDispatchAsTimeout($jobDispatchId);
                }

                exit(1);
            }

            self::logDebug("HEARTBEAT #{$heartbeatCount} | {$operationId} | PID:{$parentPid} | {$elapsed}s | {$memoryMb}MB");
        }
    }

    /**
     * Mark a JobDispatch as timed out when the parent process dies unexpectedly.
     */
    private function markJobDispatchAsTimeout(int $jobDispatchId): void
    {
        try {
            $jobDispatch = JobDispatch::find($jobDispatchId);
            if ($jobDispatch && $jobDispatch->status === JobDispatch::STATUS_RUNNING) {
                $jobDispatch->timeout();
                self::logDebug("Marked JobDispatch {$jobDispatchId} as timed out due to parent death");
            }
        } catch (\Throwable $e) {
            self::logError("Failed to mark JobDispatch {$jobDispatchId} as timed out: " . $e->getMessage());
        }
    }

    /**
     * Destructor - ensure child is cleaned up.
     */
    public function __destruct()
    {
        $this->stop();
    }
}
