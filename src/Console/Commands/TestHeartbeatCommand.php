<?php

namespace Newms87\Danx\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Newms87\Danx\Audit\AuditDriver;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Support\Heartbeat;
use Newms87\Danx\Support\SignalHandler;

class TestHeartbeatCommand extends Command
{
    protected $signature = 'test:heartbeat
        {--signal= : Signal to test (kill-parent)}
        {--duration=10 : How long to run in seconds}
        {--interval=2 : Heartbeat interval in seconds}';

    protected $description = 'Integration test for Heartbeat and SignalHandler';

    public function handle(): int
    {
        $signal = $this->option('signal');

        if ($signal === 'kill-parent') {
            return $this->testParentDeath();
        }

        return $this->testHeartbeatAndSignals();
    }

    private function testHeartbeatAndSignals(): int
    {
        // 1. Ensure AuditRequest exists for this test
        $auditRequest = AuditRequest::create([
            'session_id'  => 'test-heartbeat-session',
            'environment' => app()->environment(),
            'url'         => 'test:heartbeat',
            'request'     => ['method' => 'CLI'],
            'time'        => 0,
        ]);
        AuditDriver::$auditRequest = $auditRequest;

        $duration = (int) $this->option('duration');
        $interval = (int) $this->option('interval');

        $this->info("Starting heartbeat test (duration={$duration}s, interval={$interval}s)");
        $this->info("PID: " . getmypid());
        $this->info("To test signal handling, send SIGTERM from another terminal: kill -SIGTERM " . getmypid());

        // 2. Start heartbeat
        $heartbeat = Heartbeat::start('test-heartbeat-command', $interval);

        // 3. Set current operation via SignalHandler
        SignalHandler::setCurrentOperation('TestHeartbeatCommand');

        // 4. Sleep for duration (signals will interrupt)
        $start = time();
        while ((time() - $start) < $duration) {
            sleep(1);
            $elapsed = time() - $start;
            $this->line("Running... {$elapsed}/{$duration}s");
        }

        // 5. Stop heartbeat
        $heartbeat->stop();
        SignalHandler::setCurrentOperation(null);

        // 6. Report results
        $this->info("Test completed successfully!");
        $this->info("Check audit_request.logs for heartbeat entries:");
        $this->info("  AuditRequest ID: {$auditRequest->id}");

        // Refresh and show logs
        $auditRequest->refresh();
        if ($auditRequest->logs) {
            $this->line("\nLogs:");
            $this->line($auditRequest->logs);
        }

        return Command::SUCCESS;
    }

    private function testParentDeath(): int
    {
        if (!function_exists('pcntl_fork')) {
            $this->error('pcntl extension not available');
            return Command::FAILURE;
        }

        // 1. Create AuditRequest for this test
        $auditRequest = AuditRequest::create([
            'session_id'  => 'test-heartbeat-session',
            'environment' => app()->environment(),
            'url'         => 'test:heartbeat --signal=kill-parent',
            'request'     => ['method' => 'CLI'],
            'time'        => 0,
        ]);

        $auditRequestId = $auditRequest->id;
        $interval       = (int) $this->option('interval');

        $this->info("Testing parent death detection...");
        $this->info("AuditRequest ID: {$auditRequestId}");

        // Disconnect DB before fork
        DB::disconnect();

        $pid = pcntl_fork();

        if ($pid === -1) {
            DB::reconnect();
            $this->error('Fork failed');
            return Command::FAILURE;
        }

        if ($pid === 0) {
            // CHILD PROCESS - This will be killed, triggering PARENT_DIED in heartbeat
            DB::reconnect();

            // Re-attach to the audit request
            $childAuditRequest            = AuditRequest::find($auditRequestId);
            AuditDriver::$auditRequest    = $childAuditRequest;

            // Start heartbeat - this forks a grandchild that monitors THIS process
            $heartbeat = Heartbeat::start('parent-death-test', $interval);
            SignalHandler::setCurrentOperation('ParentDeathTest');

            // Wait a bit for heartbeat to start, then kill self
            sleep(3);

            // Kill self with SIGKILL - the heartbeat grandchild should detect this
            posix_kill(getmypid(), SIGKILL);

            exit(0); // Won't reach here
        }

        // PARENT PROCESS - waits for child to die, then checks results
        DB::reconnect();
        AuditDriver::$auditRequest = $auditRequest;

        $this->info("Forked child PID: {$pid}");
        $this->info("Child will kill itself in 3 seconds...");
        $this->info("Heartbeat grandchild should detect PARENT_DIED");

        // Wait for child to die
        $status = 0;
        pcntl_waitpid($pid, $status);

        $this->info("Child died with status: " . pcntl_wexitstatus($status));

        // Wait a moment for heartbeat to log PARENT_DIED
        sleep(2);

        // Check results
        $auditRequest->refresh();

        $this->line("\nAuditRequest logs:");
        $this->line($auditRequest->logs ?? '(no logs)');

        if (str_contains($auditRequest->logs ?? '', 'PARENT_DIED')) {
            $this->info("\n✓ SUCCESS: PARENT_DIED was detected!");
            return Command::SUCCESS;
        }

        $this->warn("\n✗ PARENT_DIED was NOT detected in logs");
        return Command::FAILURE;
    }
}
