<?php

namespace Tests\Unit\Support;

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
}
