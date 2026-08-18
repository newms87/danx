<?php

namespace Tests\Unit\Services;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;
use Tests\TestCase;

/**
 * SG-193: storeTranscodedFile() is the final choke point every transcode pipeline (PDF-to-
 * Images page splits, OCR text, LLM text) writes through. This proves the DB-level unique
 * index (migration 0015_danx_stored_files_transcode_unique_index) makes a duplicate row
 * impossible regardless of which application-level race caused a second write to be
 * attempted, and that the losing write is a harmless no-op (existing row returned) rather
 * than a thrown exception or a second row.
 */
class TranscodeFileServiceDuplicateWriteTest extends TestCase
{
    private function makeStoredFile(): StoredFile
    {
        return StoredFile::factory()->create([
            'filename' => 'source.pdf',
            'mime'     => 'application/pdf',
        ]);
    }

    /** @test */
    public function a_second_write_for_the_same_page_number_returns_the_existing_row_instead_of_creating_a_duplicate(): void
    {
        $storedFile = $this->makeStoredFile();
        $service    = app(TranscodeFileService::class);

        $first = $service->storeTranscodedFile($storedFile, TranscodeFileService::TRANSCODE_PDF_TO_IMAGES, 'page-1.png', 'first-render-bytes', 1);

        // Second write for the identical (original_stored_file_id, transcode_name, page_number)
        // -- simulates any of the races that can trigger a duplicate dispatch (stale lock
        // state, a job's own "allow duplicate once Running" debounce escape hatch, a duplicate
        // webhook delivery). Different content on purpose: proves this is a genuinely separate
        // render attempt racing the first, not a literal re-save of the same object.
        $second = $service->storeTranscodedFile($storedFile, TranscodeFileService::TRANSCODE_PDF_TO_IMAGES, 'page-1.png', 'second-render-bytes-from-a-racing-writer', 1);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $storedFile->transcodes()->where('transcode_name', TranscodeFileService::TRANSCODE_PDF_TO_IMAGES)->where('page_number', 1)->count());
    }

    /** @test */
    public function a_second_write_for_the_same_null_page_number_returns_the_existing_row_instead_of_creating_a_duplicate(): void
    {
        $storedFile = $this->makeStoredFile();
        $service    = app(TranscodeFileService::class);

        $first  = $service->storeTranscodedFile($storedFile, TranscodeFileService::TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS, 'chunk.png', 'first-bytes');
        $second = $service->storeTranscodedFile($storedFile, TranscodeFileService::TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS, 'chunk.png', 'second-racing-bytes');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $storedFile->transcodes()->where('transcode_name', TranscodeFileService::TRANSCODE_IMAGE_TO_VERTICAL_CHUNKS)->whereNull('page_number')->count());
    }

    /** @test */
    public function writes_for_different_page_numbers_are_never_deduplicated(): void
    {
        $storedFile = $this->makeStoredFile();
        $service    = app(TranscodeFileService::class);

        $page1 = $service->storeTranscodedFile($storedFile, TranscodeFileService::TRANSCODE_PDF_TO_IMAGES, 'page-1.png', 'bytes-1', 1);
        $page2 = $service->storeTranscodedFile($storedFile, TranscodeFileService::TRANSCODE_PDF_TO_IMAGES, 'page-2.png', 'bytes-2', 2);

        $this->assertNotSame($page1->id, $page2->id);
        $this->assertSame(2, $storedFile->transcodes()->count());
    }
}
