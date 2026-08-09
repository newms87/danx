<?php

namespace Tests\Unit\Jobs;

use Newms87\Danx\Jobs\TranscodeDataUrlToStoredFileJob;
use Newms87\Danx\Models\Utilities\StoredFile;
use ReflectionMethod;
use Tests\TestCase;

class TranscodeDataUrlToStoredFileJobTest extends TestCase
{
    /** @test */
    public function requires_auth_returns_false(): void
    {
        $storedFile = new StoredFile(['filename' => 'source.pdf', 'filepath' => 'source.pdf', 'url' => 'https://example.com/source.pdf', 'mime' => 'application/pdf']);

        $job = new TranscodeDataUrlToStoredFileJob($storedFile, 'ocr', ['filename' => 'f.txt', 'url' => 'https://example.com/f.txt']);

        $requiresAuth = new ReflectionMethod($job, 'requiresAuth');
        $requiresAuth->setAccessible(true);

        $this->assertFalse($requiresAuth->invoke($job));
    }

    /** @test */
    public function validate_auth_context_does_not_throw_when_unauthenticated(): void
    {
        $storedFile = new StoredFile(['filename' => 'source.pdf', 'filepath' => 'source.pdf', 'url' => 'https://example.com/source.pdf', 'mime' => 'application/pdf']);

        $job = new TranscodeDataUrlToStoredFileJob($storedFile, 'ocr', ['filename' => 'f.txt', 'url' => 'https://example.com/f.txt']);

        $validateAuthContext = new ReflectionMethod($job, 'validateAuthContext');
        $validateAuthContext->setAccessible(true);

        // No exception expected -- this job must run correctly with no authenticated
        // user/team (the OCR callback webhook path it's reached from has neither).
        $validateAuthContext->invoke($job);
        $this->addToAssertionCount(1);
    }
}
