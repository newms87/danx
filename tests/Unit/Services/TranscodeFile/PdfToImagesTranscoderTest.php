<?php

namespace Tests\Unit\Services\TranscodeFile;

use Newms87\Danx\Api\ConvertApi\ConvertApi;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFile\PdfToImagesTranscoder;
use Tests\TestCase;

/**
 * SG-205: dispatchDataUrlBatch()'s synchronous insert needs each page's byte size up front
 * (to avoid StoredFile::booted()'s remote-HEAD backfill firing per row) -- ConvertAPI already
 * returns FileSize in its response, it just wasn't surfaced. This proves run() passes it
 * through.
 */
class PdfToImagesTranscoderTest extends TestCase
{
    /** @test */
    public function run_surfaces_convert_apis_file_size_per_page(): void
    {
        $storedFile = StoredFile::factory()->create(['mime' => 'application/pdf']);

        $this->mock(ConvertApi::class)
            ->shouldReceive('pdfToImage')
            ->once()
            ->andReturn([
                'Files' => [
                    ['FileName' => 'a.png', 'Url' => 'https://example.com/a.png', 'FileSize' => 111],
                    ['FileName' => 'b.png', 'Url' => 'https://example.com/b.png', 'FileSize' => 222],
                ],
            ]);

        $result = (new PdfToImagesTranscoder)->run($storedFile);

        $this->assertSame(111, $result[0]['size']);
        $this->assertSame(222, $result[1]['size']);
    }

    /** @test */
    public function run_leaves_size_null_when_convert_api_omits_file_size(): void
    {
        $storedFile = StoredFile::factory()->create(['mime' => 'application/pdf']);

        $this->mock(ConvertApi::class)
            ->shouldReceive('pdfToImage')
            ->once()
            ->andReturn([
                'Files' => [
                    ['FileName' => 'a.png', 'Url' => 'https://example.com/a.png'],
                ],
            ]);

        $result = (new PdfToImagesTranscoder)->run($storedFile);

        $this->assertNull($result[0]['size']);
    }
}
