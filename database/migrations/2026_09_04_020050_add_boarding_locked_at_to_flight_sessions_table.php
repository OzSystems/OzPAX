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
        Schema::table('flight_sessions', function (Blueprint $table) {
            // Set the first time this session's passenger manifest is
            // decided - either when it starts moving on the ground or when
            // it nears its filed departure time (see PassengerBoardingEngine).
            // Once set, the manifest is final and never re-evaluated.
            $table->timestamp('boarding_locked_at')->nullable()->after('connected_on_ground');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_sessions', function (Blueprint $table) {
            $table->dropColumn('boarding_locked_at');
        });
    }
};
