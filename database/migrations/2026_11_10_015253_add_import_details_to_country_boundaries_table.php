<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('country_boundaries', function (Blueprint $table) {
            $table->unsignedInteger('vertex_count')->nullable()->after('max_lng');
            $table->decimal('simplify_tolerance', 8, 5)->nullable()->after('attribution');
            $table->dateTime('imported_at')->nullable()->after('simplify_tolerance');
        });
    }

    public function down(): void
    {
        Schema::table('country_boundaries', function (Blueprint $table) {
            $table->dropColumn(['vertex_count', 'simplify_tolerance', 'imported_at']);
        });
    }
};
