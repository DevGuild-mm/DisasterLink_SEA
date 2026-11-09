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
        // 1. Shelters, Evacuation Centers, Hospitals & Relief Distribution Hubs
        Schema::create('shelters', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('type')->default('shelter'); // shelter, hospital, relief_center, evacuation_center
            $table->string('country');
            $table->string('city');
            $table->string('address');
            $table->double('latitude', 10, 6);
            $table->double('longitude', 10, 6);
            $table->string('contact_name');
            $table->string('contact_phone');
            $table->string('contact_email')->nullable();
            $table->unsignedInteger('total_capacity')->default(100);
            $table->unsignedInteger('current_occupancy')->default(0);
            $table->string('status')->default('open'); // open, full, closed, evacuating
            $table->json('facilities')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('country');
            $table->index('type');
            $table->index('status');
        });

        // 2. Emergency Resources & Stock Inventory
        Schema::create('emergency_resources', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('shelter_id')->nullable();
            $table->string('category'); // water_food, medical, rescue_equipment, necessities
            $table->string('item_name');
            $table->unsignedInteger('quantity')->default(0);
            $table->string('unit')->default('units'); // liters, rations, kits, sets, boxes
            $table->unsignedInteger('min_threshold')->default(10);
            $table->string('status')->default('adequate'); // adequate, low, critical, depleted
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('shelter_id')->references('id')->on('shelters')->nullOnDelete();
            $table->index('shelter_id');
            $table->index('category');
            $table->index('status');
        });

        // 3. Resource Distribution Logs
        Schema::create('resource_distributions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('resource_id');
            $table->string('shelter_id')->nullable();
            $table->string('recipient');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('distributed_by');
            $table->timestamp('distributed_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('resource_id')->references('id')->on('emergency_resources')->cascadeOnDelete();
            $table->foreign('shelter_id')->references('id')->on('shelters')->nullOnDelete();
            $table->index('resource_id');
            $table->index('shelter_id');
        });

        // 4. Evacuation Routes & Transit Guidelines
        Schema::create('evacuation_routes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->string('country');
            $table->string('origin');
            $table->string('destination');
            $table->double('distance_km', 8, 2)->default(0);
            $table->string('status')->default('clear'); // clear, caution, impassable
            $table->string('hazard_warning')->nullable();
            $table->text('instructions');
            $table->json('waypoints')->nullable();
            $table->boolean('is_verified')->default(true);
            $table->string('verified_by')->nullable();
            $table->timestamps();

            $table->index('country');
            $table->index('status');
        });

        // 5. Registered Volunteers & Specialized Personnel
        Schema::create('volunteers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->string('country');
            $table->string('city');
            $table->json('skills')->nullable(); // e.g. First Aid, Boat Operation, Logistics
            $table->string('availability_status')->default('available'); // available, deployed, standby
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('country');
            $table->index('availability_status');
        });

        // 6. Citizen Rescue Assistance Requests
        Schema::create('rescue_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_name');
            $table->string('contact_phone');
            $table->string('country');
            $table->string('location');
            $table->double('latitude', 10, 6)->nullable();
            $table->double('longitude', 10, 6)->nullable();
            $table->unsignedInteger('people_count')->default(1);
            $table->boolean('has_medical_emergency')->default(false);
            $table->string('urgency')->default('high'); // critical, high, moderate, low
            $table->string('status')->default('pending'); // pending, dispatched, in_progress, completed, cancelled
            $table->string('assigned_volunteer_id')->nullable();
            $table->text('situation_description');
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->foreign('assigned_volunteer_id')->references('id')->on('volunteers')->nullOnDelete();
            $table->index('country');
            $table->index('status');
            $table->index('urgency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rescue_requests');
        Schema::dropIfExists('volunteers');
        Schema::dropIfExists('evacuation_routes');
        Schema::dropIfExists('resource_distributions');
        Schema::dropIfExists('emergency_resources');
        Schema::dropIfExists('shelters');
    }
};
