<?php

namespace Tests\Feature\Resources;

use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Resources\StoredFileResource;
use Tests\TestCase;

/**
 * SG-485: StoredFileResource::details() default shape.
 *
 * `children` (every transcode) is a closure on StoredFileResource::data() — per
 * ActionResource::make(), a closure is included only when explicitly named. Before this
 * fix, StoredFileResource had no details() override, so a details() call naming no
 * fields fell through to the base ActionResource::details() default of ['*' => true],
 * forcing `children` on unconditionally. One local file with 488 transcodes serialized
 * to 1.1MB that way. The new default is `{thumb, optimized}` only.
 */
class StoredFileResourceTest extends TestCase
{
    public function test_details_with_no_fields_defaults_to_thumb_and_optimized_only(): void
    {
        $storedFile = StoredFile::factory()->create();
        StoredFile::factory()->create(['original_stored_file_id' => $storedFile->id]);

        $response = StoredFileResource::details($storedFile);

        $this->assertArrayHasKey('thumb', $response);
        $this->assertArrayHasKey('optimized', $response);
        $this->assertArrayNotHasKey('children', $response, 'transcodes must not be sent unless explicitly requested');
    }

    public function test_details_with_explicit_children_field_returns_the_transcodes(): void
    {
        $storedFile = StoredFile::factory()->create();
        $transcode  = StoredFile::factory()->create(['original_stored_file_id' => $storedFile->id]);

        $response = StoredFileResource::details($storedFile, ['children' => true]);

        $this->assertCount(1, $response['children']);
        $this->assertSame($transcode->id, $response['children'][0]['id']);
    }
}
