<?php

namespace Newms87\Danx\Jobs;

use Newms87\Danx\Helpers\LockHelper;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

class TranscodeDataUrlToStoredFileJob extends Job
{
    /**
     * 120-second worker timeout. The download inside TranscodeFileService::migrateTranscodedFileToStorage
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
     * lives in migrateTranscodedFileToStorage()), so it is the right place to bound it --
     * not the dispatch site, which would need to know a queue-infrastructure detail (the
     * worker pool size) that varies per deployment and isn't this service's concern.
     *
     * SG-205: the row this job migrates is now inserted synchronously BEFORE this job ever
     * runs (TranscodeFileService::insertPendingTranscodedFiles()) -- this job only moves its
     * bytes to our own storage and updates that same row in place. It no longer creates
     * anything.
     */
    public const int MAX_CONCURRENT = 10;

    private StoredFile $transcodedStoredFile;

    public function __construct(StoredFile $transcodedStoredFile)
    {
        $this->transcodedStoredFile = $transcodedStoredFile;
        parent::__construct();
    }

    public function ref(): string
    {
        return 'transcode-data-url-to-stored-file:' . $this->transcodedStoredFile->id;
    }

    /**
     * Reached exclusively via the async migration batch TranscodeFileService::
     * dispatchDataUrlBatch() dispatches after inserting the pending row -- no user/team
     * session exists on a queue worker. Safe: migrateTranscodedFileToStorage() updates a
     * row whose team_id was already set explicitly at insert time, never from Auth/team()
     * globals.
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
            app(TranscodeFileService::class)->migrateTranscodedFileToStorage($this->transcodedStoredFile);
        } finally {
            LockHelper::release($slotKey);
        }
    }
}
