<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('disaster_reports', function (Blueprint $table) {
            $table->index('country');
            $table->index('created_at');
            $table->index(['status', 'country']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('disaster_reports', function (Blueprint $table) {
            $table->dropIndex(['country']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['status', 'country']);
        });
    }
};
