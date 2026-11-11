<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('agency_name');
            $table->string('category', 40);
            $table->string('phone', 50);
            $table->string('alt_phone', 50)->nullable();
            $table->string('website', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['country_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
    }
};
