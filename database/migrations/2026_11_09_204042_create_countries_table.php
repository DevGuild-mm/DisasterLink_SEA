<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('iso2', 2)->unique();
            $table->string('iso3', 3)->unique();
            $table->string('name');
            $table->string('timezone', 64);
            $table->string('capital_name');
            $table->decimal('capital_lat', 9, 6);
            $table->decimal('capital_lng', 9, 6);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
