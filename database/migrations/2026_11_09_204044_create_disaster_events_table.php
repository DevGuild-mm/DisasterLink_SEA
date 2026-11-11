<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disaster_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('external_id', 120);
            $table->string('episode_id', 60)->nullable();
            $table->string('event_type', 30);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location_text')->nullable();
            $table->string('alert_level', 20)->nullable();
            $table->boolean('alert_level_derived')->default(false);
            $table->decimal('magnitude', 4, 2)->nullable();
            $table->decimal('severity_value', 12, 3)->nullable();
            $table->string('severity_unit', 30)->nullable();
            $table->decimal('depth_km', 7, 2)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('source_updated_at')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->json('source_meta')->nullable();
            $table->foreignId('country_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('country_status', 20)->default('unresolved');
            $table->string('country_method', 20)->nullable();
            $table->string('country_note')->nullable();
            $table->foreignId('canonical_event_id')->nullable()->constrained('disaster_events')->nullOnDelete();
            $table->string('alert_status', 20)->default('not_eligible');
            $table->dateTime('threshold_met_at')->nullable();
            $table->dateTime('alerts_completed_at')->nullable();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('last_synced_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index('occurred_at');
            $table->index(['country_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index('country_status');
            $table->index(['alert_status', 'updated_at']);
            $table->index('threshold_met_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disaster_events');
    }
};
