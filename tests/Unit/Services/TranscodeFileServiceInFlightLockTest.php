<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Bus;
use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Jobs\RecoverTranscodePagesJob;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFile\PdfToImagesTranscoder;
use Newms87\Danx\Services\TranscodeFileService;
use Tests\TestCase;

/**
 * SG-193: transcode() has no guard against being called a second time while the batch
 * dispatched by an earlier call for the SAME StoredFile+transcodeName is still draining.
 * A second call mid-drain sees only the handful of rows written so far and misreads it as
 * "most pages missing", dispatching a full duplicate render. Reproduced on a real 434-page
 * COPELAND run: a second call 8 seconds after the initial dispatch read 5/434 rows and
 * "recovered" the other 429, doubling almost every page.
 */
class TranscodeFileServiceInFlightLockTest extends TestCase
{
    private const string TRANSCODE_NAME = TranscodeFileService::TRANSCODE_PDF_TO_IMAGES;

    private function makeStoredFile(): StoredFile
    {
        return StoredFile::factory()->create([
            'filename' => 'source.pdf',
            'mime'     => 'application/pdf',
        ]);
    }

    /** @test */
    public function a_second_transcode_call_mid_drain_does_not_dispatch_a_duplicate_recovery(): void
    {
        Bus::fake();

        $storedFile = $this->makeStoredFile();

        // First call: no existing rows -- dispatches the initial full batch (10 fake pages)
        // and acquires the in-flight lock.
        $this->mock(PdfToImagesTranscoder::class)
            ->shouldReceive('usesDataUrls')->andReturn(true)
            ->shouldReceive('startingProgress')->andReturn(90.0)
            ->shouldReceive('timeEstimate')->andReturn(5000)
            ->shouldReceive('getTimeout')->andReturn(1800)
            ->shouldReceive('run')->once()->andReturn(array_map(
                fn(int $i) => ['filename' => "page-$i.png", 'url' => "https://example.com/page-$i.png", 'page_number' => $i],
                range(1, 10),
            ));

        app(TranscodeFileService::class)->transcode(self::TRANSCODE_NAME, $storedFile);

        // Simulate the batch still draining: only 2 of the 10 dispatched pages have actually
        // persisted their row so far (the other 8 are still queued behind the concurrency
        // semaphore). This mirrors the exact real-world state the bug hit.
        $storedFile->transcodes()->create([
            'transcode_name' => self::TRANSCODE_NAME,
            'page_number'    => 1,
            'filename'       => 'page-1.png',
            'filepath'       => 'transcodes/page-1.png',
        ]);
        $storedFile->transcodes()->create([
            'transcode_name' => self::TRANSCODE_NAME,
            'page_number'    => 2,
            'filename'       => 'page-2.png',
            'filepath'       => 'transcodes/page-2.png',
        ]);

        // Second call: existing is non-empty (2 rows) but incomplete (8 "missing"). Without
        // the in-flight lock this would dispatch RecoverTranscodePagesJob for those 8 pages
        // -- a real duplicate render, since the FIRST batch is still legitimately in flight
        // for all 10.
        //
        // Cleared for realism (two genuinely separate processes each start with an empty
        // $acquiredLocks) but no longer load-bearing for this test's assertion: the lock
        // calls now use LockHelper::getDistributed(), which never reads or writes
        // $acquiredLocks at all -- see the un-cleared sibling test below, which is the one
        // that actually proves the same-process case (the one that was silently broken).
        LockHelper::$acquiredLocks = [];

        app(TranscodeFileService::class)->transcode(self::TRANSCODE_NAME, $storedFile->fresh());

        Bus::assertNotDispatched(RecoverTranscodePagesJob::class);
    }

    /** @test */
    public function a_second_recovery_dispatch_on_the_same_worker_process_is_still_blocked_without_clearing_acquired_locks(): void
    {
        Bus::fake();

        $storedFile = $this->makeStoredFile();
        $storedFile->setMeta('transcodes', [
            self::TRANSCODE_NAME => ['status' => TranscodeFileService::STATUS_IN_PROGRESS, 'expected_pages' => 10],
        ])->save();
        $storedFile->transcodes()->create([
            'transcode_name' => self::TRANSCODE_NAME,
            'page_number'    => 1,
            'filename'       => 'page-1.png',
            'filepath'       => 'transcodes/page-1.png',
        ]);

        // First recovery trigger -- e.g. dispatchDataUrlBatch()'s on_complete straggler
        // recursion calling recoverIncompleteTranscode() directly after the FIRST full
        // batch's own on_complete fires. Acquires the "recover-transcode:..." lock (never
        // explicitly released -- TTL-only by design) and dispatches recovery for the 9
        // still-missing pages.
        app(TranscodeFileService::class)->recoverIncompleteTranscode($storedFile->fresh(), self::TRANSCODE_NAME);
        Bus::assertDispatched(RecoverTranscodePagesJob::class, 1);

        // Deliberately NOT clearing LockHelper::$acquiredLocks -- this is the real SG-193
        // production shape: a long-lived Horizon worker process (maxJobs=0/maxTime=0) that
        // handled the FIRST recovery trigger above is the SAME process handling this
        // SECOND, independent trigger moments later (e.g. a straggler member of the first
        // recovery batch itself completing and re-checking via the same on_complete path).
        // Reproduced live on a real 434-page COPELAND run (team 132/WR-32, 2026-08-18):
        // "Recovering 419 missing" followed 62s later by "Recovering 87 missing" for the
        // SAME file -- both well within the lock's own 300s TTL -- because
        // LockHelper::get()'s in-memory shortcut made the second call see "already
        // acquired" and skip the real (still-valid) Redis check entirely, firing a
        // duplicate render for pages already in flight from the first dispatch.
        app(TranscodeFileService::class)->recoverIncompleteTranscode($storedFile->fresh(), self::TRANSCODE_NAME);

        Bus::assertDispatched(RecoverTranscodePagesJob::class, 1);
    }

    /** @test */
    public function a_transcode_call_after_the_lock_expires_dispatches_recovery_normally(): void
    {
        Bus::fake();

        $storedFile = $this->makeStoredFile();

        // 8 of 10 expected pages already present, no in-flight lock held (simulates the
        // legitimate case: a genuinely stale/failed prior attempt, recovered later e.g. by
        // the sweeper command) -- recovery must still work.
        $storedFile->setMeta('transcodes', [
            self::TRANSCODE_NAME => ['status' => TranscodeFileService::STATUS_IN_PROGRESS, 'expected_pages' => 10],
        ])->save();
        foreach (range(1, 8) as $page) {
            $storedFile->transcodes()->create([
                'transcode_name' => self::TRANSCODE_NAME,
                'page_number'    => $page,
                'filename'       => "page-$page.png",
                'filepath'       => "transcodes/page-$page.png",
            ]);
        }

        app(TranscodeFileService::class)->transcode(self::TRANSCODE_NAME, $storedFile->fresh());

        Bus::assertDispatched(RecoverTranscodePagesJob::class);
    }
}
