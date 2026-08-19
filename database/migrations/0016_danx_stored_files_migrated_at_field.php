<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SG-205: a data-URL transcode row (PDF-to-Images) is now inserted synchronously from the
 * transcoder's manifest, before its bytes have been migrated to our own storage -- `url`
 * initially points at the transcoder's own (temporary) source. `migrated_at` distinguishes
 * that pending state (NULL) from a row whose bytes already live at `disk`/`filepath` and
 * whose `url` has been updated to point there (non-NULL). A row not created via this path
 * (e.g. a directly-uploaded file) is migrated by definition, so `up()` backfills every
 * existing row to `now()` -- only newly-inserted pending rows are ever NULL.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('stored_files', function (Blueprint $table) {
            $table->timestamp('migrated_at')->nullable();
        });

        DB::table('stored_files')->whereNull('migrated_at')->update(['migrated_at' => now()]);
    }

    public function down()
    {
        Schema::table('stored_files', function (Blueprint $table) {
            $table->dropColumn('migrated_at');
        });
    }
};
