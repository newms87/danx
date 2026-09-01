<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\Storage;
use Newms87\Danx\Exceptions\RateLimitExceededException;
use Newms87\Danx\Support\ProcessFork;
use Orchestra\Testbench\TestCase;

class ProcessForkTest extends TestCase
{
    /**
     * Test that an empty task list returns an empty result array.
     */
    public function test_empty_tasks_returns_empty_array(): void
    {
        $results = ProcessFork::run([]);

        $this->assertSame([], $results);
    }

    /**
     * Test that a single task runs and returns its result.
     * Single tasks should run without forking (optimization).
     */
    public function test_single_task_returns_result(): void
    {
        $results = ProcessFork::run([
            fn() => ['key' => 'value'],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('success', $results[0]['status']);
        $this->assertSame(['key' => 'value'], $results[0]['result']);
        $this->assertNull($results[0]['error']);
        $this->assertNull($results[0]['audit_request_id']);
    }

    /**
     * Test that multiple tasks run in parallel and all results are collected.
     * Each task returns its index to verify correct result ordering.
     */
    public function test_multiple_tasks_run_in_parallel(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $results = ProcessFork::run([
            fn() => ['batch' => 0, 'data' => 'first'],
            fn() => ['batch' => 1, 'data' => 'second'],
            fn() => ['batch' => 2, 'data' => 'third'],
        ]);

        $this->assertCount(3, $results);

        // Verify all tasks succeeded
        foreach ($results as $index => $result) {
            $this->assertSame('success', $result['status'], "Task $index failed: " . ($result['error'] ?? 'unknown'));
            $this->assertSame($index, $result['result']['batch'], "Task $index returned wrong batch index");
        }

        // Verify correct data for each task
        $this->assertSame('first', $results[0]['result']['data']);
        $this->assertSame('second', $results[1]['result']['data']);
        $this->assertSame('third', $results[2]['result']['data']);
    }

    /**
     * Test that child process exceptions are captured and returned as error results
     * instead of crashing the parent process.
     */
    public function test_child_exception_captured_as_error(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $results = ProcessFork::run([
            fn() => 'success_result',
            fn() => throw new \RuntimeException('Test error in child'),
            fn() => 'another_success',
        ]);

        $this->assertCount(3, $results);

        // First and third tasks should succeed
        $this->assertSame('success', $results[0]['status']);
        $this->assertSame('success_result', $results[0]['result']);

        $this->assertSame('success', $results[2]['status']);
        $this->assertSame('another_success', $results[2]['result']);

        // Second task should have error
        $this->assertSame('error', $results[1]['status']);
        $this->assertNull($results[1]['result']);
        $this->assertStringContainsString('Test error in child', $results[1]['error']);
    }

    /**
     * Test that the danx.process_fork.max_concurrent config defaults the cap
     * when callers don't pass maxConcurrent. Prevents DB connection exhaustion
     * from unbounded fan-out (root cause of the PDF-transcode "too many clients
     * already" production incident).
     */
    public function test_default_concurrent_cap_reads_from_config(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        config(['danx.process_fork.max_concurrent' => 3]);

        $logFile = tempnam(sys_get_temp_dir(), 'pfork_cap_');
        $tasks   = [];
        for ($i = 0; $i < 12; $i++) {
            $tasks[] = function () use ($logFile) {
                // Append start + sleep + end markers so we can count overlap windows.
                $start = microtime(true);
                file_put_contents($logFile, "START $start\n", FILE_APPEND | LOCK_EX);
                usleep(120_000);
                $end = microtime(true);
                file_put_contents($logFile, "END $end\n", FILE_APPEND | LOCK_EX);

                return 'ok';
            };
        }

        $results = ProcessFork::run($tasks);

        $this->assertCount(12, $results);
        foreach ($results as $r) {
            $this->assertSame('success', $r['status']);
        }

        // Count max concurrent overlap by walking timestamps.
        $events = [];
        foreach (file($logFile, FILE_IGNORE_NEW_LINES) as $line) {
            [$tag, $ts]  = explode(' ', $line, 2);
            $events[]    = [(float)$ts, $tag === 'START' ? 1 : -1];
        }
        usort($events, fn($a, $b) => $a[0] <=> $b[0]);

        $running     = 0;
        $maxObserved = 0;
        foreach ($events as [$_, $delta]) {
            $running += $delta;
            $maxObserved = max($maxObserved, $running);
        }
        @unlink($logFile);

        $this->assertLessThanOrEqual(3, $maxObserved, "ProcessFork did not honor config cap; saw $maxObserved concurrent forks");
    }

    /**
     * Test that concurrency limit is respected — when maxConcurrent is set,
     * only that many children run simultaneously, processing in waves.
     */
    public function test_concurrency_limit_respected(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        // Run 4 tasks with max 2 concurrent — should complete in 2 waves
        $results = ProcessFork::run(
            [
                fn() => 'task_0',
                fn() => 'task_1',
                fn() => 'task_2',
                fn() => 'task_3',
            ],
            maxConcurrent: 2
        );

        $this->assertCount(4, $results);

        // All tasks should succeed regardless of concurrency limit
        foreach ($results as $index => $result) {
            $this->assertSame('success', $result['status'], "Task $index failed: " . ($result['error'] ?? 'unknown'));
            $this->assertSame("task_$index", $result['result']);
        }
    }

    /**
     * Test that results maintain correct ordering even when tasks complete out of order.
     * Faster tasks should not shift the position of slower tasks in the results array.
     */
    public function test_result_ordering_preserved(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        // Task 0 sleeps longest, task 2 finishes first — results should still be ordered 0,1,2
        $results = ProcessFork::run([
            function () {
                usleep(100000); // 100ms

                return 'slow';
            },
            function () {
                usleep(50000); // 50ms

                return 'medium';
            },
            fn() => 'fast',
        ]);

        $this->assertCount(3, $results);
        $this->assertSame('slow', $results[0]['result']);
        $this->assertSame('medium', $results[1]['result']);
        $this->assertSame('fast', $results[2]['result']);
    }

    /**
     * Test that tasks can return various serializable types (strings, arrays, integers, null).
     */
    public function test_various_return_types(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $results = ProcessFork::run([
            fn() => 'string_value',
            fn() => 42,
            fn() => ['nested' => ['array' => true]],
            fn() => null,
        ]);

        $this->assertCount(4, $results);

        $this->assertSame('string_value', $results[0]['result']);
        $this->assertSame(42, $results[1]['result']);
        $this->assertSame(['nested' => ['array' => true]], $results[2]['result']);
        $this->assertNull($results[3]['result']);

        // All should be successful, including the null return
        foreach ($results as $result) {
            $this->assertSame('success', $result['status']);
        }
    }

    /**
     * Test that temp files are cleaned up after forking completes.
     * No pfork_ temp files should remain in the temp directory.
     */
    public function test_temp_files_cleaned_up(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        // Count existing pfork_ files before
        $tempDir     = sys_get_temp_dir();
        $beforeFiles = glob($tempDir . '/pfork_*');

        ProcessFork::run([
            fn() => 'task_1',
            fn() => 'task_2',
        ]);

        // Count after — should be same as before (all cleaned up)
        $afterFiles = glob($tempDir . '/pfork_*');

        $this->assertCount(
            count($beforeFiles),
            $afterFiles,
            'Temp files were not cleaned up after ProcessFork::run()'
        );
    }

    /**
     * Test mixed success and failure results from parallel tasks.
     * Parent should collect all results without failing itself.
     */
    public function test_mixed_success_and_failure(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $results = ProcessFork::run([
            fn() => 'ok_1',
            fn() => throw new \Exception('fail_1'),
            fn() => 'ok_2',
            fn() => throw new \Exception('fail_2'),
            fn() => 'ok_3',
        ]);

        $this->assertCount(5, $results);

        $successes = array_filter($results, fn($r) => $r['status'] === 'success');
        $errors    = array_filter($results, fn($r) => $r['status'] === 'error');

        $this->assertCount(3, $successes);
        $this->assertCount(2, $errors);

        // Verify specific positions
        $this->assertSame('ok_1', $results[0]['result']);
        $this->assertSame('error', $results[1]['status']);
        $this->assertSame('ok_2', $results[2]['result']);
        $this->assertSame('error', $results[3]['status']);
        $this->assertSame('ok_3', $results[4]['result']);
    }

    /**
     * Test that shouldContinue callback triggers cancellation of running children.
     * When the callback returns false, all active children are killed and
     * un-started tasks get 'Cancelled' error results.
     */
    public function test_should_continue_cancels_children(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $callCount = 0;

        // shouldContinue returns false on the second check — enough time for
        // children to fork but not complete their sleep
        $shouldContinue = function () use (&$callCount): bool {
            $callCount++;

            return $callCount <= 1;
        };

        $results = ProcessFork::run(
            [
                function () {
                    sleep(10); // Long task — should be killed

                    return 'should_not_finish';
                },
                function () {
                    sleep(10); // Long task — should be killed

                    return 'should_not_finish';
                },
            ],
            shouldContinue: $shouldContinue,
        );

        $this->assertCount(2, $results);

        // Both tasks should have been cancelled (not succeeded)
        foreach ($results as $index => $result) {
            $this->assertNotSame('success', $result['status'],
                "Task $index should not have succeeded — it should have been cancelled");
        }
    }

    /**
     * SG-229: the sigterm_grace_seconds config must be registered in the
     * process_fork block, mirroring max_concurrent's env+inline convention.
     *
     * ProcessForkTest extends Orchestra\Testbench\TestCase directly (no
     * getPackageProviders() override), so DanxServiceProvider::mergeConfigFrom()
     * never boots in this test class and config('danx.*') returns null for any
     * key not explicitly set at runtime — the same reason
     * test_default_concurrent_cap_reads_from_config writes its value via
     * config([...]) rather than reading an unset default. So this asserts the
     * config FILE's own registration directly rather than the (unbooted) merged
     * config; the runtime default-of-3 fallback is exercised for real by the
     * other new tests below via ProcessFork.php's own inline
     * config('danx.process_fork.sigterm_grace_seconds', 3) read.
     */
    public function test_sigterm_grace_seconds_config_registered(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/danx.php';

        $this->assertSame(3, $config['process_fork']['sigterm_grace_seconds']);
    }

    /**
     * SG-229 — Path A: cancellation detected inside waitForChildNonBlocking().
     *
     * Both children install SIG_IGN for SIGTERM (simulating a child stuck in a
     * blocked syscall that never returns to the PHP VM to run its normal SIGTERM
     * handler) then sleep far longer than the configured grace. Before the fix,
     * reapKilledChildren()'s unconditional blocking pcntl_waitpid(-1) would hang
     * forever here. After the fix, the parent must return within roughly
     * (sigterm_grace_seconds + a short SIGKILL drain) regardless of the 10s sleep.
     */
    public function test_reap_bounded_when_child_ignores_sigterm_inside_wait(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        config(['danx.process_fork.sigterm_grace_seconds' => 1]);

        $callCount      = 0;
        $shouldContinue = function () use (&$callCount): bool {
            $callCount++;

            // First call (top-of-loop, before forking) allows the fork to happen.
            // Second call (inside the wait loop, since neither child will have
            // exited yet) triggers cancellation while both children are still
            // active — this is Path A.
            return $callCount <= 1;
        };

        $start = microtime(true);

        $results = ProcessFork::run(
            [
                function () {
                    pcntl_signal(SIGTERM, SIG_IGN);
                    sleep(10); // Would hang the pre-fix reaper forever

                    return 'should_not_finish';
                },
                function () {
                    pcntl_signal(SIGTERM, SIG_IGN);
                    sleep(10); // Would hang the pre-fix reaper forever

                    return 'should_not_finish';
                },
            ],
            shouldContinue: $shouldContinue,
        );

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, "Cancellation reaping was not bounded — took {$elapsed}s (child ignores SIGTERM, must escalate to SIGKILL)");
        $this->assertCount(2, $results);

        foreach ($results as $index => $result) {
            $this->assertNotSame('success', $result['status'], "Task $index should have been cancelled, not completed");
            $this->assertSame('Cancelled', $result['error'], "Task $index's stuck child should be recorded as Cancelled after SIGKILL escalation");
        }
    }

    /**
     * SG-229 — Path B: cancellation detected at the TOP of forkAndRun()'s wave
     * loop (production scenario — a multi-wave fork where shouldContinue flips
     * false between waves, not while a wait is already in progress).
     *
     * maxConcurrent=2, 3 tasks. Task 0 finishes quickly and gets reaped normally.
     * Task 1 ignores SIGTERM and sleeps far longer than the grace. shouldContinue
     * flips false (time-based, not call-count-based, to stay robust against fork
     * scheduling jitter) only after task 0's own completion window has passed, so
     * cancellation is detected back at the top of the outer wave loop with task 1
     * still active and task 2 never yet forked ("still queued").
     *
     * Before the fix this routed into waitForChildNonBlocking()'s $alreadyCancelled
     * WNOHANG busy-loop, which spins on usleep(500_000) forever for a child that
     * never returns 0 from pcntl_waitpid — an unbounded hang distinct from (and not
     * fixed by) a Path-A-only patch of reapKilledChildren(). This test proves the
     * single-reaper invariant behaviorally: it would time out if that dead path
     * still existed.
     */
    public function test_reap_bounded_when_child_ignores_sigterm_top_of_wave_loop(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        config(['danx.process_fork.sigterm_grace_seconds' => 1]);

        $start          = microtime(true);
        $shouldContinue = function () use ($start): bool {
            // Allow task 0 (a 100ms task) plenty of time to finish and be reaped
            // via the wait loop's normal poll/sleep cadence, then cancel.
            return (microtime(true) - $start) < 0.3;
        };

        $results = ProcessFork::run(
            [
                function () {
                    usleep(100_000); // 100ms — finishes well inside the 300ms window

                    return 'fast_task';
                },
                function () {
                    pcntl_signal(SIGTERM, SIG_IGN);
                    sleep(10); // Would hang the pre-fix already-cancelled busy loop forever
                },
                fn() => 'never_started',
            ],
            maxConcurrent: 2,
            shouldContinue: $shouldContinue,
        );

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, "Top-of-wave-loop cancellation was not bounded — took {$elapsed}s");
        $this->assertCount(3, $results);

        // Task 0 had already completed successfully before cancellation fired.
        $this->assertSame('success', $results[0]['status'], 'Task 0 should have completed before cancellation was detected');
        $this->assertSame('fast_task', $results[0]['result']);

        // Task 1's stuck child was force-killed and reaped.
        $this->assertNotSame('success', $results[1]['status'], 'Task 1 (SIGTERM-ignoring) should have been cancelled');
        $this->assertSame('Cancelled', $results[1]['error']);

        // Task 2 was never forked.
        $this->assertNotSame('success', $results[2]['status'], 'Task 2 should never have been started');
        $this->assertSame('Cancelled', $results[2]['error']);
    }

    /**
     * SG-229: a child still alive after the SIGTERM grace elapses is forcibly
     * SIGKILL'd and reaped — proves the configured grace value itself drives the
     * timing (not just that the call is bounded somewhere well under the child's
     * 10s sleep), mirroring test_default_concurrent_cap_reads_from_config's
     * config-driven-behavior pattern. With sigterm_grace_seconds=1, the parent
     * must wait AT LEAST ~1s (the grace itself) but return well before the
     * grace-plus-generous-SIGKILL-drain upper bound.
     */
    public function test_sigterm_grace_seconds_shortens_bounded_return(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        config(['danx.process_fork.sigterm_grace_seconds' => 1]);

        $callCount      = 0;
        $shouldContinue = function () use (&$callCount): bool {
            $callCount++;

            return $callCount <= 1;
        };

        $start = microtime(true);

        $results = ProcessFork::run(
            [
                function () {
                    pcntl_signal(SIGTERM, SIG_IGN);
                    sleep(10);

                    return 'should_not_finish';
                },
                function () {
                    pcntl_signal(SIGTERM, SIG_IGN);
                    sleep(10);

                    return 'should_not_finish';
                },
            ],
            shouldContinue: $shouldContinue,
        );

        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.9, $elapsed, "Reaper returned before the configured 1s SIGTERM grace even elapsed — grace value is not being honored ({$elapsed}s)");
        $this->assertLessThan(4.0, $elapsed, "Reaper took far longer than the configured 1s grace + SIGKILL drain — grace value is not bounding the wait ({$elapsed}s)");

        foreach ($results as $index => $result) {
            $this->assertNotSame('success', $result['status'], "Task $index should have been cancelled");
            $this->assertSame('Cancelled', $result['error']);
        }
    }

    /**
     * Test that shouldContinue=null (default) behaves the same as before —
     * all tasks complete normally with blocking wait.
     */
    public function test_null_should_continue_runs_normally(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $results = ProcessFork::run(
            [
                fn() => 'task_a',
                fn() => 'task_b',
            ],
            shouldContinue: null,
        );

        $this->assertCount(2, $results);
        $this->assertSame('success', $results[0]['status']);
        $this->assertSame('task_a', $results[0]['result']);
        $this->assertSame('success', $results[1]['status']);
        $this->assertSame('task_b', $results[1]['result']);
    }

    /**
     * Test that success envelopes carry the error metadata keys as null so the
     * envelope shape is uniform between success and error results.
     */
    public function test_success_envelope_contains_null_error_metadata(): void
    {
        $results = ProcessFork::run([
            fn() => 'ok',
        ]);

        $this->assertSame('success', $results[0]['status']);
        $this->assertArrayHasKey('error_class', $results[0]);
        $this->assertArrayHasKey('error_code', $results[0]);
        $this->assertArrayHasKey('retry_after', $results[0]);
        $this->assertNull($results[0]['error_class']);
        $this->assertNull($results[0]['error_code']);
        $this->assertNull($results[0]['retry_after']);
    }

    /**
     * Test that a RateLimitExceededException thrown by a task yields a typed error
     * envelope: error_class, error_code, and retry_after — so callers can classify
     * the failure without string-matching the message. Single task = sequential path.
     */
    public function test_rate_limit_exception_yields_typed_error_metadata(): void
    {
        $results = ProcessFork::run([
            fn() => throw new RateLimitExceededException('x', 90),
        ]);

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame(RateLimitExceededException::class, $results[0]['error_class']);
        $this->assertSame(90, $results[0]['retry_after']);
        $this->assertSame(1001, $results[0]['error_code']);
        $this->assertStringContainsString('x', $results[0]['error']);
    }

    /**
     * Test that a plain exception yields null retry_after, its own class name,
     * and its (int) code (0 for a bare RuntimeException).
     */
    public function test_plain_exception_yields_null_rate_limit_metadata(): void
    {
        $results = ProcessFork::run([
            fn() => throw new \RuntimeException('boom'),
        ]);

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame(\RuntimeException::class, $results[0]['error_class']);
        $this->assertNull($results[0]['retry_after']);
        $this->assertSame(0, $results[0]['error_code']);
    }

    /**
     * Test that the getPrevious() chain is walked: a wrapper exception around a
     * RateLimitExceededException keeps the wrapper's class but surfaces the typed
     * exception's retry_after and code.
     */
    public function test_wrapped_rate_limit_exception_found_in_previous_chain(): void
    {
        $results = ProcessFork::run([
            function () {
                $inner = new RateLimitExceededException('blocked', 45);

                throw new \RuntimeException('wrapper failure', 0, $inner);
            },
        ]);

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame(\RuntimeException::class, $results[0]['error_class']);
        $this->assertSame(45, $results[0]['retry_after']);
        $this->assertSame(1001, $results[0]['error_code']);
        $this->assertStringContainsString('wrapper failure', $results[0]['error']);
    }

    /**
     * Test that the typed error metadata survives the forked path — serialized to
     * the child's temp file and read back by the parent.
     */
    public function test_forked_error_envelope_carries_metadata(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $results = ProcessFork::run([
            fn() => 'ok',
            fn() => throw new RateLimitExceededException('remote block', 90),
        ]);

        $this->assertSame('success', $results[0]['status']);
        $this->assertNull($results[0]['retry_after']);

        $this->assertSame('error', $results[1]['status']);
        $this->assertSame(RateLimitExceededException::class, $results[1]['error_class']);
        $this->assertSame(90, $results[1]['retry_after']);
        $this->assertSame(1001, $results[1]['error_code']);
    }

    /**
     * DEC-10 (SG-193, 2026-08-19): purgeFilesystemDisks() must actually clear
     * FilesystemManager's cached disk instances -- the same guarantee
     * purgeAllRedisConnections() already provides for Redis. Proves the fix
     * mechanism directly: resolving a disk, purging, then resolving again must
     * yield a genuinely different object instance (the cache was cleared), not
     * the same cached one.
     */
    public function test_purge_filesystem_disks_clears_the_cached_disk_instance(): void
    {
        $before = spl_object_id(Storage::disk('local'));

        ProcessFork::purgeFilesystemDisks();

        $after = spl_object_id(Storage::disk('local'));

        $this->assertNotSame($before, $after, 'purgeFilesystemDisks() must force a fresh disk instance on next resolution');
    }

    /**
     * End-to-end: many forked children, each doing a REAL write+read through a
     * Filesystem disk resolved in the parent BEFORE forking (so every child
     * would inherit a copy of that same already-open cached instance if
     * purging didn't happen), must all succeed with their own correct content.
     * Mirrors the existing DB-connection-isolation guarantee for Filesystem/S3
     * clients -- the real defect this session traced (a fraction of forked
     * children hitting League\Flysystem\UnableToReadFile under real fork
     * concurrency, never reproducible outside it).
     */
    public function test_many_forked_children_each_get_a_working_isolated_filesystem_disk(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        // Resolve (and warm) the disk in the parent BEFORE forking.
        Storage::disk('local')->put('pfork-warm.txt', 'warm');

        $tasks = [];
        for ($i = 0; $i < 8; $i++) {
            $tasks[] = function () use ($i) {
                $path    = "pfork-child-$i.txt";
                $content = "child-$i-content";
                Storage::disk('local')->put($path, $content);

                return Storage::disk('local')->get($path) === $content;
            };
        }

        $results = ProcessFork::run($tasks);

        $this->assertCount(8, $results);
        foreach ($results as $index => $result) {
            $this->assertSame('success', $result['status'], "Child $index failed: " . ($result['error'] ?? 'unknown'));
            $this->assertTrue($result['result'], "Child $index's own write+read round-trip did not match — filesystem disk corruption under fork");
        }

        for ($i = 0; $i < 8; $i++) {
            Storage::disk('local')->delete("pfork-child-$i.txt");
        }
        Storage::disk('local')->delete('pfork-warm.txt');
    }

    /**
     * SG-177 — a stray child left behind by an EARLIER run in the same long-lived
     * process must be drained immediately, not at 2 per second.
     *
     * `pcntl_waitpid(-1, ..., WNOHANG)` reaps ANY child of the host process, not
     * only the ones this run forked. A `queue:work` process hosts many ProcessFork
     * runs over its lifetime, and any run interrupted before it reaps leaves its
     * children parented to that worker, where they accumulate (1,441 were observed
     * under one worker). Before the fix, reaping a PID that was not in
     * $activeChildren fell through to the bottom-of-loop usleep(500_000), so N
     * strays cost N/2 seconds before the loop could even observe its OWN children
     * exiting — which is how a 16-second fan-out blew a 540-second deadline.
     *
     * This test plants STRAYS zombies parented to the PHPUnit process, then runs a
     * multi-wave fan-out. maxConcurrent:1 guarantees the wait loop is entered for
     * every task, and a $shouldContinue callback is REQUIRED: forkAndRun only takes
     * the non-blocking waitForChildNonBlocking() path when one is supplied, and the
     * 500ms sleep this regression is about lives at the bottom of that loop. Without
     * the callback the run uses the blocking pcntl_waitpid() branch, which never
     * sleeps and so cannot exhibit the bug at all.
     *
     * With the fix, the strays are drained on the first few WNOHANG calls and the run
     * finishes in well under a second; without it, the floor is STRAYS * 0.5s
     * regardless of how fast the real tasks are.
     */
    public function test_stray_children_are_drained_without_paying_the_wait_sleep(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $strays    = 20;
        $strayPids = [];

        for ($i = 0; $i < $strays; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork() failed while planting stray children');
            }

            if ($pid === 0) {
                // Child: exit immediately, mirroring ProcessFork::forkChild()'s own
                // child exit. Nobody reaps it, so it stays a zombie on our PID.
                exit(0);
            }

            $strayPids[] = $pid;
        }

        // Let every stray actually reach zombie state before timing anything —
        // a stray still running is not the condition under test.
        $this->waitForZombies($strayPids);

        $started = microtime(true);
        $results = ProcessFork::run(
            [
                fn() => 'a',
                fn() => 'b',
                fn() => 'c',
                fn() => 'd',
            ],
            maxConcurrent: 1,
            shouldContinue: fn() => true
        );
        $elapsed = microtime(true) - $started;

        $this->assertCount(4, $results);
        foreach ($results as $index => $result) {
            $this->assertSame('success', $result['status'], "Task $index failed: " . ($result['error'] ?? 'unknown'));
        }
        $this->assertSame(['a', 'b', 'c', 'd'], array_column($results, 'result'), 'Strays must not corrupt this run\'s own results');

        // Pre-fix floor was $strays * 0.5s = 10s. Half of that is comfortably
        // above any real cost of 4 trivial forks and far below the broken path.
        $this->assertLessThan(
            $strays * 0.25,
            $elapsed,
            sprintf(
                'Run took %.2fs with %d strays planted — the wait loop is paying a sleep per stray reaped',
                $elapsed,
                $strays
            )
        );

        // Every stray must have been reaped BY the run — that is the mechanism,
        // not just a side effect. A timing assertion alone could pass for the wrong
        // reason (e.g. the loop exiting early), so confirm none is reapable now:
        // pcntl_waitpid returns -1/ECHILD only once the PID is no longer our child.
        foreach ($strayPids as $pid) {
            $this->assertSame(
                -1,
                pcntl_waitpid($pid, $status, WNOHANG),
                "Stray $pid is still an unreaped child after the run — ProcessFork did not drain it"
            );
        }
    }

    /**
     * Block until every listed PID is a zombie (exited, unreaped) or the deadline
     * passes. Reads /proc/<pid>/stat's third field, the process state character.
     */
    private function waitForZombies(array $pids): void
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $pending = 0;

            foreach ($pids as $pid) {
                $stat = @file_get_contents("/proc/$pid/stat");

                // A vanished /proc entry means something else already reaped it,
                // which cannot happen here — but treat it as settled either way.
                if ($stat === false) {
                    continue;
                }

                // Fields after the (comm) parenthesis: state is the first of them.
                $after = substr($stat, (int)strrpos($stat, ')') + 2);

                if (($after[0] ?? '') !== 'Z') {
                    $pending++;
                }
            }

            if ($pending === 0) {
                return;
            }

            usleep(10_000);
        }

        $this->fail('Stray children never reached zombie state — the precondition for this test does not hold');
    }
}
