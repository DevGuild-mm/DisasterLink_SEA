<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HLD v1.4 §28.8: manual (admin) syncs are tracked in `sync_runs`, so the table gets a trigger, the admin who
 * asked, a request id that groups the rows of one "all" request, the queue time, and a warning for a successful
 * run that had a non-fatal problem. A queued run has no start time yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->string('trigger', 20)->default('scheduler')->after('status');
            $table->foreignId('requested_by')->nullable()->after('trigger')->constrained('users')->nullOnDelete();
            $table->uuid('request_uuid')->nullable()->after('requested_by')->index();
            $table->dateTime('queued_at')->nullable()->after('request_uuid');
            $table->string('warning_message', 500)->nullable()->after('error_message');
            $table->dateTime('started_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropIndex(['request_uuid']);
            $table->dropColumn(['trigger', 'request_uuid', 'queued_at', 'warning_message']);
            $table->dateTime('started_at')->nullable(false)->change();
        });
    }
};
