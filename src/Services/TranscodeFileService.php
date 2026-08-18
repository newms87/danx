<?php

namespace Newms87\Danx\Services;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Newms87\Danx\Exceptions\ApiException;
use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Jobs\RecoverTranscodePagesJob;
use Newms87\Danx\Jobs\TranscodeDataUrlToStoredFileJob;
use Newms87\Danx\Jobs\TranscodeStoredFileJob;
use Newms87\Danx\Models\Job\JobBatch;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Repositories\FileRepository;
use Newms87\Danx\Services\TranscodeFile\FileTranscoderAbstract;
use Newms87\Danx\Services\TranscodeFile\ImageToVerticalChunksTranscoder;
use Newms87\Danx\Services\TranscodeFile\PdfToImagesTranscoder;
use Newms87\Danx\Traits\HasDebugLogging;

class TranscodeFileService
{
    use HasDebugLogging;

    const string
        STATUS_PENDING     = 'Pending',
        STATUS_IN_PROGRESS = 'In Progress',
        STATUS_TIMEOUT     = 'Timeout',
        STATUS_COMPLETE    = 'Complete';

    const string
        TRANSCODE_PDF_TO_IMAGES            = 'PDF to Images',
        TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS = 'Image to Vertical Chunks';

    const array TRANSCODERS = [
        self::TRANSCODE_PDF_TO_IMAGES            => PdfToImagesTranscoder::class,
        self::TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS => ImageToVerticalChunksTranscoder::class,
    ];

    // TTL for the recovery-in-flight lock (recoverIncompleteTranscode's idempotency guard).
    // Comfortably longer than any single recovery round (render + store) should take, so a
    // legitimate NEW recovery request isn't skipped once the in-flight one has actually
    // finished. Auto-expires — no explicit release needed.
    const int RECOVERY_LOCK_TTL_SECONDS = 300;

    // TTL for transcode()'s own in-flight lock (SG-193). transcode()'s "existing rows present
    // but incomplete" branch has no way to distinguish "a prior attempt genuinely failed
    // partway" from "the initial dispatchDataUrlBatch() from THIS SAME transcode() call is
    // still draining" -- a second transcode() call landing mid-drain (e.g. a duplicate
    // trigger, a UI-driven resolve-and-kick-off race) sees only the handful of rows written
    // so far and misreads it as most pages missing, dispatching a full duplicate render.
    // Reproduced 2026-08-18 on a real 434-page COPELAND run: a second call 8 seconds after
    // the initial dispatch read 5/434 rows and "recovered" the other 429, doubling almost
    // every page. Sized like RECOVERY_LOCK_TTL_SECONDS -- comfortably longer than the
    // largest expected page-image batch takes to fully drain through the queue. Auto-expires
    // -- no explicit release needed, matching this class's existing lock idiom.
    const int TRANSCODE_DISPATCH_LOCK_TTL_SECONDS = 300;

    public function storeTranscodedFile(StoredFile $storedFile, $transcodeName, $filename, $data, ?int $pageNumber = null): StoredFile
    {
        $dir                                     = $storedFile->id;
        $filepath                                = "transcodes/$transcodeName/$dir/$filename";
        $transcodedFile                          = app(FileRepository::class)->createFileWithContents($filepath, $data);
        $transcodedFile->team_id                 = $storedFile->team_id;
        $transcodedFile->original_stored_file_id = $storedFile->id;
        $transcodedFile->transcode_name          = $transcodeName;
        $transcodedFile->page_number             = $pageNumber ?? $storedFile->page_number;
        $transcodedFile->save();

        return $transcodedFile;
    }

    /**
     * Returns a Transcoder instance for the given transcode name
     */
    public function getTranscoder(string $transcodeName): FileTranscoderAbstract
    {
        $transcoder = self::TRANSCODERS[$transcodeName] ?? null;

        if (!$transcoder) {
            throw new ApiException("Transcoder not found: $transcodeName");
        }

        return app($transcoder);
    }

    /**
     * Dispatch a transcode job for the stored file
     */
    public function dispatch(string $transcodeName, StoredFile $storedFile, array $options = []): TranscodeStoredFileJob
    {
        $storedFile->setMeta('transcodes', [
            $transcodeName => [
                'status'       => self::STATUS_PENDING,
                'progress'     => 0,
                'requested_at' => now(),
                'started_at'   => null,
                'timeout_at'   => now()->addSeconds($this->getTranscoder($transcodeName)->getTimeout($storedFile)),
                'completed_at' => null,
            ],
        ])->save();

        return (new TranscodeStoredFileJob($storedFile, $transcodeName))->dispatch();
    }

    /**
     * Perform the transcode on the stored file.
     *
     * When the StoredFile already has a partial set of transcodes — i.e. fewer rows than the
     * `expected_pages` recorded in `meta.transcodes.{name}.expected_pages` — the missing pages
     * are auto-recovered instead of returning the stale partial set. This handles the case
     * where the first transcode batch had members that timed out / failed mid-download:
     * counter-fix + this gate together mean the file heals itself on the next consumer touch.
     */
    public function transcode(string $transcodeName, StoredFile $storedFile, array $options = []): Collection
    {
        static::logDebug("Transcode $transcodeName: $storedFile");

        $existing = $storedFile->transcodes()->where('transcode_name', $transcodeName)->get();
        $lockKey  = "transcode-dispatch:$transcodeName:{$storedFile->id}";

        if ($existing->isNotEmpty()) {
            $missingPages = $this->getMissingPageNumbers($storedFile, $transcodeName, $existing);

            if (empty($missingPages)) {
                static::logDebug("Already has complete $transcodeName transcodes");

                return $existing;
            }

            if (!LockHelper::get($lockKey, self::TRANSCODE_DISPATCH_LOCK_TTL_SECONDS)) {
                static::logDebug("Transcode dispatch already in flight for $transcodeName $storedFile, skipping duplicate recovery dispatch");

                return $existing;
            }

            static::logDebug('Recovering ' . count($missingPages) . " missing $transcodeName page(s) for $storedFile");

            $this->dispatchPartialBatch($storedFile, $transcodeName, $missingPages);

            return $existing;
        }

        if (!LockHelper::get($lockKey, self::TRANSCODE_DISPATCH_LOCK_TTL_SECONDS)) {
            static::logDebug("Transcode dispatch already in flight for $transcodeName $storedFile, skipping duplicate initial dispatch");

            return $existing;
        }

        $this->start($storedFile, $transcodeName);

        $transcoder      = $this->getTranscoder($transcodeName);
        $transcodedFiles = $transcoder->run($storedFile, $options);

        $this->recordExpectedPages($storedFile, $transcodeName, count($transcodedFiles));

        // If this service uses data URLs instead of the raw file data, we can run this in
        // parallel and download the data from the URLs in a job instead of all in a single
        // execution
        if ($transcoder->usesDataUrls()) {
            $this->dispatchDataUrlBatch($storedFile, $transcodeName, $transcodedFiles);
        } else {
            // Raw-data path: the bytes are already in memory, save inline
            foreach ($transcodedFiles as $transcodedFile) {
                $transcodedFile = $this->storeTranscodedFile($storedFile, $transcodeName, $transcodedFile['filename'], $transcodedFile['data'], $transcodedFile['page_number'] ?? null);
                $existing->push($transcodedFile);
            }

            $this->complete($storedFile, $transcodeName);
        }

        return $existing;
    }

    /**
     * Recover missing pages of an already-dispatched data-URL transcode. Called by the
     * scheduled sweeper and by consumer code (e.g. FileResolutionService) when partial state
     * is detected. Idempotent — running twice on a healthy SF is a no-op.
     */
    public function recoverIncompleteTranscode(StoredFile $storedFile, string $transcodeName): bool
    {
        $existing     = $storedFile->transcodes()->where('transcode_name', $transcodeName)->get();
        $missingPages = $this->getMissingPageNumbers($storedFile, $transcodeName, $existing);

        if (empty($missingPages)) {
            // Heal a status stuck In Progress/Pending even though enough real pages already
            // exist — e.g. a sub-document extracted from a combined upload keeps its pages
            // numbered by their ORIGINAL position (435-439 for a 5-page doc that was pages
            // 435-439 of a 439-page combined PDF). getMissingPageNumbers already treats that
            // as complete, but nothing else ever flips the status back to Complete, so the
            // SF is_transcoding flag would otherwise stay stuck true forever.
            $status = $storedFile->meta['transcodes'][$transcodeName]['status'] ?? null;
            if ($status !== self::STATUS_COMPLETE) {
                $this->complete($storedFile, $transcodeName);
            }

            return false;
        }

        static::logDebug('Recovering ' . count($missingPages) . " missing $transcodeName page(s) for $storedFile");

        return $this->dispatchPartialBatch($storedFile, $transcodeName, $missingPages);
    }

    /**
     * Return the page numbers that the transcode is expected to produce but does not yet
     * have on disk. Empty array = healthy (or expected_pages unknown — legacy rows).
     *
     * @param  Collection<int,StoredFile>|null  $existing  optional preloaded transcode rows
     * @return int[]
     */
    public function getMissingPageNumbers(StoredFile $storedFile, string $transcodeName, ?Collection $existing = null): array
    {
        $expectedPages = $storedFile->meta['transcodes'][$transcodeName]['expected_pages'] ?? null;

        if (!$expectedPages || $expectedPages <= 0) {
            return [];
        }

        $rows = $existing
            ?? $storedFile->transcodes()->where('transcode_name', $transcodeName)->get();

        $presentPages = $rows
            ->pluck('page_number')
            ->filter()
            ->map(fn($n) => (int)$n)
            ->all();

        // Page numbering isn't always contiguous-from-1 — a sub-document extracted from a
        // combined upload can keep its pages numbered by their ORIGINAL position (e.g.
        // 435-439 for a 5-page doc that was pages 435-439 of a 439-page combined PDF).
        // Once at least `expectedPages` distinct real rows exist, there's nothing left to
        // recover regardless of what the actual numbers are — checking literal membership
        // in [1, expectedPages] below would otherwise never be satisfiable for such a file
        // and recovery would loop forever.
        if (count(array_unique($presentPages)) >= $expectedPages) {
            return [];
        }

        $missing = [];
        for ($page = 1; $page <= $expectedPages; $page++) {
            if (!in_array($page, $presentPages, true)) {
                $missing[] = $page;
            }
        }

        return $missing;
    }

    /**
     * Persist the expected page count on the SF meta so recovery can detect partial state.
     */
    private function recordExpectedPages(StoredFile $storedFile, string $transcodeName, int $expectedPages): void
    {
        if ($expectedPages <= 0) {
            return;
        }

        $current = $storedFile->meta['transcodes'][$transcodeName] ?? [];
        $storedFile->setMeta('transcodes', [
            $transcodeName => array_merge($current, ['expected_pages' => $expectedPages]),
        ])->save();
    }

    /**
     * Flip the SF back to "In Progress" synchronously, then dispatch the actual page
     * render (RecoverTranscodePagesJob) asynchronously. Returns false (no-op) when a
     * recovery for this SF+transcode is already in flight.
     *
     * SG-729: the render call (e.g. ConvertAPI's HTTP request) used to run inline here,
     * in whatever job called this — TaskOrchestratorJob among them (via
     * recoverIncompleteTranscode()) and the first-time transcode path (via transcode()).
     * For a large document, or when the caller's rate-limit budget is exhausted, that
     * HTTP call can block far longer than the caller's own job timeout, silently
     * consuming its entire execution budget. The render call now runs in its own
     * isolated, retryable job instead.
     *
     * The status flip MUST stay synchronous here: FileResolutionService::resolveStoredFile()
     * checks is_transcoding immediately after this method returns and only enters
     * waitForTranscoding() if it's true. Moving the flip into the async job would race —
     * the caller could observe is_transcoding=false and consume a still-partial page set
     * before the job ever runs.
     *
     * Idempotency guard: both callers (transcode() and recoverIncompleteTranscode(), plus
     * the on_complete straggler recursion in dispatchDataUrlBatch()) can independently
     * detect the same partial state. A held lock means a recovery is already in flight —
     * skip firing a redundant duplicate external render call; the in-flight recovery (or
     * the next sweeper pass) will pick up any pages still missing once it finishes.
     */
    private function dispatchPartialBatch(StoredFile $storedFile, string $transcodeName, array $pageNumbers): bool
    {
        $lockKey = "recover-transcode:$transcodeName:{$storedFile->id}";
        if (!LockHelper::get($lockKey, self::RECOVERY_LOCK_TTL_SECONDS)) {
            static::logDebug("Recovery already in flight for $transcodeName page(s) of $storedFile, skipping duplicate dispatch");

            return false;
        }

        $this->start($storedFile, $transcodeName);

        (new RecoverTranscodePagesJob($storedFile, $transcodeName, $pageNumbers))->dispatch();

        return true;
    }

    /**
     * Render a specific set of missing pages and store them. Runs inside
     * RecoverTranscodePagesJob — kept as a public service method (not inlined in the
     * job) so it mirrors transcode()'s existing service-owns-the-logic shape and can be
     * exercised directly in tests without touching the queue.
     */
    public function recoverPages(StoredFile $storedFile, string $transcodeName, array $pageNumbers): void
    {
        $transcoder      = $this->getTranscoder($transcodeName);
        $transcodedFiles = $transcoder->run($storedFile, ['page_numbers' => $pageNumbers]);

        $this->dispatchDataUrlBatch($storedFile, $transcodeName, $transcodedFiles);
    }

    /**
     * Create a JobBatch of TranscodeDataUrlToStoredFileJob members. on_complete marks the
     * SF status Complete and runs one round of recovery — so a single batch-member failure
     * does not require a sweep to reach the next attempt.
     */
    private function dispatchDataUrlBatch(StoredFile $storedFile, string $transcodeName, array $transcodedFiles): void
    {
        $batchJobs = [];
        foreach ($transcodedFiles as $transcodedFile) {
            $batchJobs[] = (new TranscodeDataUrlToStoredFileJob($storedFile, $transcodeName, $transcodedFile));
        }

        $storedFileId = $storedFile->id;
        JobBatch::createForJobs(
            "Store transcoded files for $transcodeName",
            $batchJobs,
            function () use ($storedFileId, $transcodeName) {
                $sf = StoredFile::find($storedFileId);
                if (!$sf) {
                    return;
                }

                $service = app(TranscodeFileService::class);
                $service->complete($sf, $transcodeName);

                // Refresh meta then check for stragglers. dispatchPartialBatch will fire a
                // second batch when rows < expected_pages — the SF is re-flipped to
                // In Progress and the cycle continues until pages match or operator picks
                // up the SF via the sweeper.
                $sf->refresh();
                $service->recoverIncompleteTranscode($sf, $transcodeName);
            },
        );
    }

    public function moveDataUrlToStoredFile(StoredFile $storedFile, string $transcodeName, array $transcodedFile): StoredFile
    {
        $filename   = $transcodedFile['filename']    ?? null;
        $url        = $transcodedFile['url']         ?? null;
        $pageNumber = $transcodedFile['page_number'] ?? null;

        if (!$url) {
            throw new Exception('Transcoded file does not have a URL');
        }

        if (!$filename) {
            throw new Exception('Transcoded file does not have a filename');
        }

        // 50-second HTTP timeout + 1 retry (100ms backoff). Worst case ~100s — fits inside the
        // 120s TranscodeDataUrlToStoredFileJob worker timeout. Replaces a naked file_get_contents
        // which had no timeout and could be SIGTERM'd by Laravel's default 60s worker timeout
        // mid-download, stranding the JobBatch counter and starving the on_complete callback.
        try {
            $response = Http::timeout(50)
                ->retry(2, 100, throw: false)
                ->get($url)
                ->throw();
            $data = $response->body();
        } catch (RequestException|ConnectionException $exception) {
            throw new Exception("Failed to download transcoded file from $url: " . $exception->getMessage(), 0, $exception);
        }

        return $this->storeTranscodedFile($storedFile, $transcodeName, $filename, $data, $pageNumber);
    }

    /**
     * Mark the transcoded file as started
     */
    public function start(StoredFile $storedFile, $transcodeName): void
    {
        $transcoder = $this->getTranscoder($transcodeName);
        $progress   = $transcoder->startingProgress($storedFile);
        $estimate   = $transcoder->timeEstimate($storedFile);

        $storedFile->setMeta('transcodes', [
            $transcodeName => [
                'status'       => self::STATUS_IN_PROGRESS,
                'progress'     => $progress,
                'estimate_ms'  => $estimate,
                'started_at'   => now(),
                'timeout_at'   => now()->addSeconds($this->getTranscoder($transcodeName)->getTimeout($storedFile)),
                'completed_at' => null,
            ],
        ])->save();
    }

    /**
     * Mark the transcoded file as completed
     */
    public function complete(StoredFile $storedFile, $transcodeName): void
    {
        $storedFile->setMeta('transcodes', [
            $transcodeName => [
                'status'       => self::STATUS_COMPLETE,
                'progress'     => 100,
                'completed_at' => now(),
            ],
        ])->save();
    }
}
