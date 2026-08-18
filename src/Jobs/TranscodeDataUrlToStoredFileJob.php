<?php

namespace Newms87\Danx\Jobs;

use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

class TranscodeDataUrlToStoredFileJob extends Job
{
    /**
     * 120-second worker timeout. The download inside TranscodeFileService::moveDataUrlToStoredFile
     * uses 50s + 1 retry (worst case ~100s) so this floor is wide enough to allow a retry
     * to complete inside the worker without SIGTERM.
     */
    public int $timeout = 120;

    /**
     * SG-201: TranscodeFileService::dispatchDataUrlBatch() fans this job out once per page of
     * a rendered PDF with no concurrency cap of its own -- a several-hundred-page document
     * queues that many instances at once. Each run() call opens its own DB connection; a
     * queue worker pool sized close to (or equal to) the database's own connection ceiling
     * leaves no headroom for anything else running concurrently and real jobs fail with
     * SQLSTATE[08006] "too many clients already" under that load (reproduced 2026-08-18,
     * gpt-manager job_batches id 13: 31 real connection-refused failures on a 434-page batch).
     *
     * This job is the one place that concurrency is actually spent (the DB-touching work
     * lives in moveDataUrlToStoredFile()), so it is the right place to bound it -- not the
     * dispatch site, which would need to know a queue-infrastructure detail (the worker pool
     * size) that varies per deployment and isn't this service's concern.
     */
    public const int MAX_CONCURRENT = 10;

    private StoredFile $storedFile;

    private string $transcodeName;

    private array $transcodedFile;

    public function __construct(StoredFile $storedFile, string $transcodeName, array $transcodedFile)
    {
        $this->storedFile     = $storedFile;
        $this->transcodeName  = $transcodeName;
        $this->transcodedFile = $transcodedFile;
        parent::__construct();
    }

    public function ref(): string
    {
        return 'transcode-data-url-to-stored-file:' . $this->transcodeName . ':' . $this->storedFile->id . ':' . md5(json_encode($this->transcodedFile));
    }

    /**
     * Reached exclusively via the OCR callback chain (OcrCallbackController ->
     * ProcessOcrCallbackJob -> TranscodePrerequisiteService::persistOcrResult ->
     * TranscodeFileService::moveDataUrlToStoredFile) -- a machine-to-machine
     * webhook authenticated by a callback token, never a Laravel user session,
     * so no user/team context exists to inherit. Safe: moveDataUrlToStoredFile()
     * -> storeTranscodedFile() sets team_id explicitly from the already-resolved
     * $storedFile relation, never from Auth/team() globals. Mirrors
     * ProcessOcrCallbackJob's identical override for the same reason.
     */
    protected function requiresAuth(): bool
    {
        return false;
    }

    /**
     * Bounds how many instances of this job run their DB-touching work at once, regardless
     * of how many were dispatched. A fixed pool of MAX_CONCURRENT lock keys acts as a
     * counting semaphore: this job's deterministic slot (derived from its own ref(), stable
     * across a queue-worker retry) blocks on LockHelper::acquire() -- a Redis wait, not a DB
     * connection -- until a slot frees up, then holds it only for its own critical section.
     */
    private function concurrencySlotKey(): string
    {
        return 'transcode-data-url-slot:' . (crc32($this->ref()) % self::MAX_CONCURRENT);
    }

    public function run()
    {
        $slotKey = $this->concurrencySlotKey();
        LockHelper::acquire($slotKey);

        try {
            app(TranscodeFileService::class)->moveDataUrlToStoredFile($this->storedFile, $this->transcodeName, $this->transcodedFile);
        } finally {
            LockHelper::release($slotKey);
        }
    }
}
