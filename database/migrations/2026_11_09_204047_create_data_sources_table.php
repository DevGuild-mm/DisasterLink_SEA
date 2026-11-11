<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->string('name');
            $table->string('pipeline', 20);
            $table->unsignedSmallInteger('interval_minutes');
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('last_fetched_count')->nullable();
            $table->unsignedInteger('last_processed_count')->nullable();
            $table->dateTime('last_empty_success_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
