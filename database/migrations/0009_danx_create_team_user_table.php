<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('team_user')) {
            return;
        }

        Schema::create('team_user', function (Blueprint $table) {
            $table->uuid('team_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->primary(['team_id', 'user_id']);
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
    }
};
