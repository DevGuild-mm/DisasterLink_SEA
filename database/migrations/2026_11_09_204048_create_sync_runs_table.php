<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 40);
            $table->string('status', 20);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('source_key')->references('key')->on('data_sources')->cascadeOnDelete();
            $table->index(['source_key', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
