<?php

namespace Newms87\Danx\Jobs;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

/**
 * Renders a specific set of missing transcode pages and stores them.
 *
 * Split out of TranscodeFileService::dispatchPartialBatch() (SG-729) so the
 * transcoder's external HTTP call (e.g. ConvertAPI) never runs synchronously
 * inline inside a caller job with a fixed timeout budget (TaskOrchestratorJob,
 * TaskWorkerJob, the sweeper command). The caller only needs to flip the
 * StoredFile back to "In Progress" (TranscodeFileService::start(), synchronous)
 * before dispatching this job — see dispatchPartialBatch() for the ordering
 * requirement.
 */
class RecoverTranscodePagesJob extends Job
{
    public function __construct(
        protected StoredFile $storedFile,
        protected string $transcodeName,
        protected array $pageNumbers
    ) {
        parent::__construct();
    }

    public function ref(): string
    {
        return 'recover-transcode-pages:' . $this->transcodeName . ':' . $this->storedFile->id;
    }

    /**
     * Dispatched asynchronously from TaskOrchestratorJob/TaskWorkerJob/the sweeper command
     * (see class docblock) -- those callers run with a real authenticated user/team, but
     * that context does not survive onto this job's own queue-worker execution. Safe:
     * run() never touches Auth/team() globals -- recoverPages() calls the transcoder (an
     * external HTTP call) then dispatchDataUrlBatch(), whose TranscodeDataUrlToStoredFileJob
     * members set team_id explicitly from the resolved StoredFile relation (SG-98's
     * identical fix, same reasoning).
     */
    protected function requiresAuth(): bool
    {
        return false;
    }

    public function run()
    {
        app(TranscodeFileService::class)->recoverPages($this->storedFile, $this->transcodeName, $this->pageNumbers);
    }
}
