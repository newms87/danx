<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The audit_request table was created without a primary key constraint.
        // Add it so we can create a self-referencing foreign key.
        $hasPrimaryKey = DB::selectOne(
            "SELECT constraint_name FROM information_schema.table_constraints WHERE table_name = 'audit_request' AND constraint_type = 'PRIMARY KEY'"
        );

        if (!$hasPrimaryKey) {
            Schema::table('audit_request', function (Blueprint $table) {
                $table->primary('id');
            });
        }

        Schema::table('audit_request', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('id');
            $table->unsignedInteger('children_count')->default(0)->after('parent_id');
            $table->foreign('parent_id')->references('id')->on('audit_request')->nullOnDelete();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_request', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'children_count']);
        });
    }
};
