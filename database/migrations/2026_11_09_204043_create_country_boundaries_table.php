<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_boundaries', function (Blueprint $table) {
            $table->foreignId('country_id')->primary()->constrained()->cascadeOnDelete();
            $table->longText('geojson');
            $table->decimal('min_lat', 9, 6);
            $table->decimal('max_lat', 9, 6);
            $table->decimal('min_lng', 9, 6);
            $table->decimal('max_lng', 9, 6);
            $table->string('source');
            $table->string('source_version')->nullable();
            $table->date('downloaded_at')->nullable();
            $table->string('license')->nullable();
            $table->string('attribution')->nullable();
            $table->timestamps();

            $table->index(['min_lat', 'max_lat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_boundaries');
    }
};
