<?php

namespace Newms87\Danx\Resources;

use Illuminate\Database\Eloquent\Model;
use Newms87\Danx\Models\Utilities\StoredFile;
use Newms87\Danx\Services\TranscodeFileService;

class StoredFileResource extends ActionResource
{
    /**
     * A details() call that names no fields defaults to thumb + optimized only — not
     * every transcode. `children` is a closure (see data() below) that returns every
     * transcode row via ActionResource::collection(); the base ActionResource::details()
     * default of ['*' => true] would force it on unconditionally. One local file with
     * 488 transcodes serialized to 1.1MB that way (production tops out at 866). Callers
     * that need the full transcode list ask for it by name, e.g.
     * static::details($storedFile, ['children' => true]).
     */
    public static function details(Model $model, ?array $includeFields = null): array
    {
        return static::make($model, $includeFields ?? ['thumb' => true, 'optimized' => true]);
    }

    public static function data(StoredFile $storedFile, array $includeFields = []): array
    {
        return [
            'id'             => $storedFile->id,
            'name'           => $storedFile->filename,
            'url'            => $storedFile->url,
            'mime'           => $storedFile->mime,
            'size'           => $storedFile->size,
            'location'       => $storedFile->location,
            'meta'           => $storedFile->meta,
            'page_number'    => $storedFile->page_number,
            'is_transcoding' => $storedFile->is_transcoding,
            'created_at'     => $storedFile->created_at,
            'updated_at'     => $storedFile->updated_at,
            'thumb'          => fn($fields) => static::getThumb($storedFile),
            'optimized'      => fn($fields) => static::getThumb($storedFile),
            'children'       => fn($fields) => StoredFileResource::collection($storedFile->transcodes()->get(), $fields),
        ];
    }

    /**
     * Get the thumb for a stored file, as a real record ({@see ActionResource::typedData()} —
     * `__type` + `id`), in the same shape any other StoredFileResource::make() call produces.
     *
     * NOTE: Only applicable to PDF files for now
     *
     * Deliberately calls static::make($thumb) with NO $includeFields: per
     * ActionResource::make(), a field backed by a closure (this class's own `thumb`,
     * `optimized`, and `children`) is only included when explicitly named. A PDF page's
     * thumb IS the page itself (`$thumb === $storedFile` in the `original_stored_file_id`
     * branch above), so recursing into ITS thumb/optimized would call getThumb() on the
     * same row again — infinite recursion. Omitting $includeFields entirely, rather than
     * passing `['thumb' => false, 'optimized' => false]`, keeps this correct even if a
     * future closure field is added here without this method being touched.
     */
    public static function getThumb(?StoredFile $storedFile): ?array
    {
        if (!$storedFile) {
            return null;
        }

        $thumb = null;

        if ($storedFile->isPdf()) {
            if ($storedFile->original_stored_file_id) {
                $thumb = $storedFile;
            } else {
                $thumb = $storedFile->transcodes()->where('transcode_name', TranscodeFileService::TRANSCODE_PDF_TO_IMAGES)->first();
            }
        }

        if (!$thumb) {
            return null;
        }

        return static::make($thumb);
    }
}
