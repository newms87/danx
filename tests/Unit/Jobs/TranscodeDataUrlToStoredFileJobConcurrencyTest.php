<?php

namespace Tests\Unit\Jobs;

use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Jobs\TranscodeDataUrlToStoredFileJob;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Support\ProcessFork;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SG-201: dispatchDataUrlBatch() fans out one TranscodeDataUrlToStoredFileJob per page of a
 * rendered PDF with no concurrency cap of its own. This proves the fix -- a fixed pool of
 * MAX_CONCURRENT LockHelper slot keys acting as a counting semaphore -- actually bounds
 * concurrent execution under real OS-process concurrency, not just in a single PHP process.
 */
class TranscodeDataUrlToStoredFileJobConcurrencyTest extends TestCase
{
    /** @test */
    public function concurrent_jobs_never_exceed_max_concurrent_slots(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $logPath = tempnam(sys_get_temp_dir(), 'sg201-concurrency-');
        unlink($logPath);

        $taskCount = 30;
        $tasks     = [];

        for ($i = 0; $i < $taskCount; $i++) {
            $tasks[] = function () use ($i, $logPath) {
                $storedFile = new StoredFile([
                    'filename' => 'source.pdf',
                    'filepath' => 'source.pdf',
                    'url'      => 'https://example.com/source.pdf',
                    'mime'     => 'application/pdf',
                ]);

                $job = new TranscodeDataUrlToStoredFileJob($storedFile, 'PDF to Images', [
                    'filename' => "page-$i.png",
                    'url'      => "https://example.com/page-$i.png",
                    'page'     => $i,
                ]);

                $slotKeyMethod = new ReflectionMethod($job, 'concurrencySlotKey');
                $slotKeyMethod->setAccessible(true);
                $slotKey = $slotKeyMethod->invoke($job);

                LockHelper::acquire($slotKey);

                $start = microtime(true);
                file_put_contents($logPath, json_encode(['slot' => $slotKey, 'event' => 'start', 'time' => $start]) . "\n", FILE_APPEND | LOCK_EX);

                // Deterministic hold, long enough that genuine parallel execution across
                // slots (and genuine queueing within a slot) is unambiguous in the log.
                usleep(200_000);

                $end = microtime(true);
                file_put_contents($logPath, json_encode(['slot' => $slotKey, 'event' => 'end', 'time' => $end]) . "\n", FILE_APPEND | LOCK_EX);

                LockHelper::release($slotKey);

                return ['slot' => $slotKey, 'start' => $start, 'end' => $end];
            };
        }

        $results = ProcessFork::run($tasks, maxConcurrent: 20);

        foreach ($results as $index => $result) {
            $this->assertSame('success', $result['status'], "Task $index failed: " . ($result['error'] ?? 'unknown'));
        }

        $lines = array_filter(explode("\n", file_get_contents($logPath)));
        unlink($logPath);

        $intervals = $this->pairIntervals($lines);
        $this->assertCount($taskCount, $intervals, 'Every task must contribute exactly one start/end interval to the log.');

        // MAX_CONCURRENT distinct slot keys exist, and LockHelper::acquire() is a real
        // mutex per key, so no two intervals sharing a slot may ever overlap in time.
        $this->assertNoOverlapWithinSameSlot($intervals);

        // The bound this fix exists to prove: at no instant do more than MAX_CONCURRENT
        // jobs hold their slot lock simultaneously, even though 20 processes were allowed
        // to run at once at the OS level.
        $maxConcurrentObserved = $this->maxOverlappingIntervals($intervals);
        $this->assertLessThanOrEqual(
            TranscodeDataUrlToStoredFileJob::MAX_CONCURRENT,
            $maxConcurrentObserved,
            'Concurrent slot holders must never exceed MAX_CONCURRENT.'
        );

        // Prove the test actually exercised real parallelism (not accidentally serialized
        // by some outer lock), so the bound above is a meaningful ceiling, not a vacuous one.
        $this->assertGreaterThan(1, $maxConcurrentObserved, 'Test must observe genuine concurrent execution to be meaningful.');

        $distinctSlotsUsed = count(array_unique(array_column($intervals, 'slot')));
        $this->assertGreaterThan(1, $distinctSlotsUsed, 'Test must exercise more than one slot to be meaningful.');
    }

    private function pairIntervals(array $lines): array
    {
        $starts    = [];
        $intervals = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry['event'] === 'start') {
                $starts[$entry['slot']][] = $entry['time'];
            } else {
                $start       = array_shift($starts[$entry['slot']]);
                $intervals[] = ['slot' => $entry['slot'], 'start' => $start, 'end' => $entry['time']];
            }
        }

        return $intervals;
    }

    private function assertNoOverlapWithinSameSlot(array $intervals): void
    {
        $bySlot = [];
        foreach ($intervals as $interval) {
            $bySlot[$interval['slot']][] = $interval;
        }

        foreach ($bySlot as $slot => $slotIntervals) {
            usort($slotIntervals, fn($a, $b) => $a['start'] <=> $b['start']);
            for ($i = 1; $i < count($slotIntervals); $i++) {
                $this->assertGreaterThanOrEqual(
                    $slotIntervals[$i - 1]['end'],
                    $slotIntervals[$i]['start'],
                    "Slot $slot had two overlapping holders -- LockHelper mutex failed to serialize them."
                );
            }
        }
    }

    private function maxOverlappingIntervals(array $intervals): int
    {
        $events = [];
        foreach ($intervals as $interval) {
            $events[] = ['time' => $interval['start'], 'delta' => 1];
            $events[] = ['time' => $interval['end'], 'delta' => -1];
        }

        // Ends sort before starts at the same instant so a closing interval frees its slot
        // before a new one is counted -- otherwise a same-tick handoff double-counts.
        usort($events, fn($a, $b) => $a['time'] <=> $b['time'] ?: $a['delta'] <=> $b['delta']);

        $current = 0;
        $max     = 0;
        foreach ($events as $event) {
            $current += $event['delta'];
            $max = max($max, $current);
        }

        return $max;
    }
}
