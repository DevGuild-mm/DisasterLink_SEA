<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capital_weather', function (Blueprint $table) {
            $table->foreignId('country_id')->primary()->constrained()->cascadeOnDelete();
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->unsignedSmallInteger('weather_code')->nullable();
            $table->string('condition', 80)->nullable();
            $table->string('icon', 40)->nullable();
            $table->decimal('precipitation_mm', 7, 2)->nullable();
            $table->decimal('wind_speed_kmh', 6, 2)->nullable();
            $table->unsignedTinyInteger('humidity_pct')->nullable();
            $table->decimal('forecast_min_c', 5, 2)->nullable();
            $table->decimal('forecast_max_c', 5, 2)->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->dateTime('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_weather');
    }
};
