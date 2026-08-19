<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Newms87\Danx\Jobs\TranscodeDataUrlToStoredFileJob;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFile\PdfToImagesTranscoder;
use Newms87\Danx\Services\TranscodeFileService;
use Tests\TestCase;

/**
 * SG-205: dispatchDataUrlBatch() used to wait for every page's download+upload to complete
 * before flipping is_transcoding false, blocking any consumer of waitForTranscoding() for
 * the full batch duration. It now inserts every row synchronously from the transcoder's own
 * manifest (no downloads) and flips is_transcoding immediately; the actual bytes move to our
 * storage asynchronously via TranscodeDataUrlToStoredFileJob, which now migrates an
 * already-inserted row in place instead of creating one.
 */
class TranscodeFileServiceEarlyInsertTest extends TestCase
{
    private const string TRANSCODE_NAME = TranscodeFileService::TRANSCODE_PDF_TO_IMAGES;

    private function makeStoredFile(): StoredFile
    {
        return StoredFile::factory()->create([
            'filename' => 'source.pdf',
            'mime'     => 'application/pdf',
        ]);
    }

    private function mockTranscoder(array $pages): void
    {
        $this->mock(PdfToImagesTranscoder::class)
            ->shouldReceive('usesDataUrls')->andReturn(true)
            ->shouldReceive('startingProgress')->andReturn(90.0)
            ->shouldReceive('timeEstimate')->andReturn(5000)
            ->shouldReceive('getTimeout')->andReturn(1800)
            ->shouldReceive('run')->once()->andReturn($pages);
    }

    /** @test */
    public function is_transcoding_flips_false_immediately_without_waiting_for_any_download(): void
    {
        Bus::fake();

        $storedFile = $this->makeStoredFile();

        $this->mockTranscoder([
            ['filename' => 'page-1.png', 'url' => 'https://convertapi.example.com/page-1.png', 'page_number' => 1, 'size' => 111],
            ['filename' => 'page-2.png', 'url' => 'https://convertapi.example.com/page-2.png', 'page_number' => 2, 'size' => 222],
        ]);

        // No HTTP fake registered at all -- if anything in this call tried to actually
        // download a page's bytes, Http::preventStrayRequests() (enabled globally in this
        // suite's TestCase, matching the rest of this file's siblings) would fail the test.
        // Reaching a passing assertion here IS the proof no download happened synchronously.
        app(TranscodeFileService::class)->transcode(self::TRANSCODE_NAME, $storedFile);

        $storedFile->refresh();
        $this->assertFalse($storedFile->is_transcoding, 'is_transcoding must already be false -- nothing should still be waiting on a download.');

        $rows = $storedFile->transcodes()->orderBy('page_number')->get();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->migrated_at, 'A freshly-inserted row has not been migrated yet.');
        $this->assertNull($rows[1]->migrated_at);
        $this->assertSame('https://convertapi.example.com/page-1.png', $rows[0]->url, 'url should point at the transcoder\'s source until migration runs.');
        $this->assertSame(111, $rows[0]->size, 'size comes straight from the manifest -- no remote HEAD/download needed to know it.');
    }

    /** @test */
    public function disk_and_filepath_are_precomputed_to_their_final_destination_at_insert_time(): void
    {
        Bus::fake();

        $storedFile = $this->makeStoredFile();

        $this->mockTranscoder([
            ['filename' => 'page-1.png', 'url' => 'https://convertapi.example.com/page-1.png', 'page_number' => 1, 'size' => 111],
        ]);

        app(TranscodeFileService::class)->transcode(self::TRANSCODE_NAME, $storedFile);

        $row = $storedFile->transcodes()->first();

        $this->assertSame(config('filesystems.default'), $row->disk);
        $this->assertSame('transcodes/' . self::TRANSCODE_NAME . "/{$storedFile->id}/page-1.png", $row->filepath);
    }

    /** @test */
    public function a_colliding_insert_returns_the_existing_row_instead_of_throwing_or_duplicating(): void
    {
        $storedFile = $this->makeStoredFile();
        $service    = app(TranscodeFileService::class);

        $insert = new \ReflectionMethod($service, 'insertPendingTranscodedFiles');
        $insert->setAccessible(true);

        $page = ['filename' => 'page-1.png', 'url' => 'https://convertapi.example.com/page-1.png', 'page_number' => 1, 'size' => 111];

        // Two independent insert attempts for the identical (original_stored_file_id,
        // transcode_name, page_number) -- simulates a losing race against SG-193's partial
        // unique index (e.g. a retried dispatch), the same shape TranscodeFileServiceDuplicateWriteTest
        // already proves for storeTranscodedFile(). This proves the SAME guarantee holds for
        // the new insert-only path: createOrFirst() re-queries and returns the winning
        // writer's row rather than throwing or creating a second one.
        $first  = $insert->invoke($service, $storedFile, self::TRANSCODE_NAME, [$page]);
        $second = $insert->invoke($service, $storedFile, self::TRANSCODE_NAME, [
            ['filename' => 'page-1.png', 'url' => 'https://convertapi.example.com/a-racing-writers-url.png', 'page_number' => 1, 'size' => 999],
        ]);

        $this->assertSame(1, $storedFile->transcodes()->where('page_number', 1)->count(), 'No duplicate row.');
        $this->assertSame($first->first()->id, $second->first()->id);
        // createOrFirst() never overwrites an existing row's attributes with the "create"
        // side's values -- the first writer's own url/size survive untouched.
        $this->assertSame('https://convertapi.example.com/page-1.png', $second->first()->url);
        $this->assertSame(111, $second->first()->size);
    }

    /** @test */
    public function the_migration_job_downloads_and_updates_the_same_row_in_place_never_a_new_one(): void
    {
        $storedFile = $this->makeStoredFile();

        $pending = $storedFile->transcodes()->create([
            'transcode_name' => self::TRANSCODE_NAME,
            'page_number'    => 1,
            'filename'       => 'page-1.png',
            'filepath'       => 'transcodes/' . self::TRANSCODE_NAME . "/{$storedFile->id}/page-1.png",
            'disk'           => config('filesystems.default'),
            'url'            => 'https://convertapi.example.com/page-1.png',
            'size'           => 111,
        ]);

        Http::fake([
            'https://convertapi.example.com/page-1.png' => Http::response('the-real-rendered-bytes', 200),
        ]);

        app(TranscodeFileService::class)->migrateTranscodedFileToStorage($pending);

        $this->assertSame(1, StoredFile::where('original_stored_file_id', $storedFile->id)->where('page_number', 1)->count(), 'Migration must UPDATE the existing row, never insert a second one.');

        $pending->refresh();
        $this->assertNotNull($pending->migrated_at);
        $this->assertNotSame('https://convertapi.example.com/page-1.png', $pending->url, 'url must now point at our own storage, not the transcoder source.');
        $this->assertSame(strlen('the-real-rendered-bytes'), $pending->size);
    }

    /** @test */
    public function the_migration_job_reuses_the_sg201_concurrency_slot_lock(): void
    {
        // Not a behavior assertion -- a structural guard against silently dropping SG-201's
        // fix while adapting this job's constructor for SG-205. TranscodeDataUrlToStoredFileJobConcurrencyTest
        // is the real proof the lock bounds real concurrency; this just confirms the job
        // still computes a slot key at all.
        $storedFile     = new StoredFile(['filename' => 'p.png', 'filepath' => 'p.png', 'url' => 'https://example.com/p.png', 'mime' => 'image/png']);
        $storedFile->id = 'structural-guard-row';

        $job = new TranscodeDataUrlToStoredFileJob($storedFile);

        $slotKeyMethod = new \ReflectionMethod($job, 'concurrencySlotKey');
        $slotKeyMethod->setAccessible(true);

        $this->assertStringStartsWith('transcode-data-url-slot:', $slotKeyMethod->invoke($job));
    }
}
