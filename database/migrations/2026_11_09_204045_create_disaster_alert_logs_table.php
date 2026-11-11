<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disaster_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('disaster_event_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('failure_type', 20)->nullable();
            $table->string('suppression_reason', 50)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'disaster_event_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disaster_alert_logs');
    }
};
