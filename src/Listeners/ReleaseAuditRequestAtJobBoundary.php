<?php

namespace Newms87\Danx\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Newms87\Danx\Audit\AuditDriver;

/**
 * Releases the current audit request at the boundaries of every job a queue worker runs — a danx
 * Job or a plain Laravel job, whatever its class — so nothing logged outside a job attaches to it.
 *
 * Why: a queue worker's process outlives the job it runs (a Horizon worker loops; a warm Vapor
 * queue Lambda keeps its booted application across invocations), and the current audit request
 * is a static ({@see AuditDriver::$auditRequest}). Only danx Jobs replaced it, in
 * Job::__unserialize(), so a plain job run after a danx Job logged onto the danx Job's audit
 * request. SG-488: FetchDevProgressDayJob's GitHub 401 errors landed on TaskOrchestratorJob audit
 * requests minutes after those jobs completed, and counted as the extraction run's errors.
 *
 * - JobProcessing: a job starts with no audit request, whatever ran before it in the process and
 *   however that ended.
 * - JobProcessed: a job that completed releases its audit request as it ends.
 * - A FAILED job is deliberately not released at its failure events (JobExceptionOccurred /
 *   JobFailed): Illuminate\Queue\Worker::runJob() reports the job's exception only after those
 *   fire, and that report is the job's own error — it must land on the job's audit request. It is
 *   the last thing the worker does for the job; the next job's JobProcessing releases it.
 * - A SyncJob (dispatchSync / the sync connection) runs inline inside its caller's unit of work —
 *   an HTTP request, a command, another job — so its start and end are not boundaries: releasing
 *   there would strand the caller's remaining work without its audit request.
 */
class ReleaseAuditRequestAtJobBoundary
{
    public function handle(JobProcessing|JobProcessed $event): void
    {
        if ($event->job instanceof SyncJob) {
            return;
        }

        AuditDriver::releaseAuditRequest();
    }
}
