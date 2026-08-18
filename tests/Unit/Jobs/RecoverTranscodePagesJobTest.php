<?php

namespace Tests\Unit\Jobs;

use Newms87\Danx\Jobs\RecoverTranscodePagesJob;
use Newms87\Danx\Models\Utilities\StoredFile;
use ReflectionMethod;
use Tests\TestCase;

class RecoverTranscodePagesJobTest extends TestCase
{
    /** @test */
    public function requires_auth_returns_false(): void
    {
        $storedFile = new StoredFile(['filename' => 'source.pdf', 'filepath' => 'source.pdf', 'url' => 'https://example.com/source.pdf', 'mime' => 'application/pdf']);

        $job = new RecoverTranscodePagesJob($storedFile, 'PDF to Images', [3, 4, 5]);

        $requiresAuth = new ReflectionMethod($job, 'requiresAuth');
        $requiresAuth->setAccessible(true);

        $this->assertFalse($requiresAuth->invoke($job));
    }

    /** @test */
    public function validate_auth_context_does_not_throw_when_unauthenticated(): void
    {
        $storedFile = new StoredFile(['filename' => 'source.pdf', 'filepath' => 'source.pdf', 'url' => 'https://example.com/source.pdf', 'mime' => 'application/pdf']);

        $job = new RecoverTranscodePagesJob($storedFile, 'PDF to Images', [3, 4, 5]);

        $validateAuthContext = new ReflectionMethod($job, 'validateAuthContext');
        $validateAuthContext->setAccessible(true);

        // No exception expected -- this job runs from a queue worker that has lost the
        // dispatching caller's authenticated session (TaskOrchestratorJob/TaskWorkerJob/
        // the sweeper command all dispatch it asynchronously).
        $validateAuthContext->invoke($job);
        $this->addToAssertionCount(1);
    }
}
