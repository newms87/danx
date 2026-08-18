<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SG-193: DB-level backstop making a duplicate transcode row impossible regardless of
 * any application-level race (dispatch-guard lock miss, duplicate webhook delivery,
 * concurrent worker resume, etc.) — the PDF-to-Images (ConvertAPI), OCR, and LLM
 * transcode pipelines all funnel every write through
 * TranscodeFileService::storeTranscodedFile(), which was a plain unguarded INSERT.
 * Two partial unique indexes (Postgres) cover both shapes a transcoded child row takes:
 * page-numbered (the common case) and page-number-less (e.g. a single-page source with
 * no page_number of its own). Both exclude soft-deleted rows so the S3-404 self-heal in
 * TranscodePrerequisiteService::getValidTranscode() (delete + recreate) keeps working.
 */
return new class extends Migration {
    public function up()
    {
        DB::statement(<<<SQL
            CREATE UNIQUE INDEX stored_files_transcode_unique
                ON stored_files (original_stored_file_id, transcode_name, page_number)
                WHERE original_stored_file_id IS NOT NULL
                    AND transcode_name IS NOT NULL
                    AND page_number IS NOT NULL
                    AND deleted_at IS NULL
        SQL);

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX stored_files_transcode_unique_null_page
                ON stored_files (original_stored_file_id, transcode_name)
                WHERE original_stored_file_id IS NOT NULL
                    AND transcode_name IS NOT NULL
                    AND page_number IS NULL
                    AND deleted_at IS NULL
        SQL);
    }

    public function down()
    {
        DB::statement('DROP INDEX IF EXISTS stored_files_transcode_unique');
        DB::statement('DROP INDEX IF EXISTS stored_files_transcode_unique_null_page');
    }
};
