<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('name_id')->nullable()->index();
            // Where the current leg of the journey is being planned from -
            // rolled forward to match current_icao at every interim stop
            // (see PassengerBoardingEngine::handleLegCompleted), not a fixed
            // record of the very first departure airport. The true original
            // starting point is always recoverable from the passenger's
            // first passenger_flight_history row.
            $table->string('origin_icao')->index();
            $table->string('destination_icao')->index();
            // Current ground location. Meaningless (holds the departed-from
            // airport) while status = 'boarded' - the live manifest join is
            // via boarded_flight_session_id, not this column.
            $table->string('current_icao')->index();
            $table->string('status', 16)->default('waiting')->index();
            $table->foreignId('boarded_flight_session_id')->nullable()->index();
            // Explicit defaults (never actually used - the app always
            // supplies a real value) purely to stop MariaDB's legacy
            // behavior of auto-assigning "DEFAULT CURRENT_TIMESTAMP ON
            // UPDATE CURRENT_TIMESTAMP" to the first NOT NULL TIMESTAMP
            // column with no explicit default - which would otherwise
            // silently reset this column to "now" on every update.
            $table->timestamp('generated_at')->default(DB::raw("'2000-01-01 00:00:00'"));
            // Reset on every boarding and every landing - drives the 7-day
            // stranding expiry (see ExpireStrandedPassengers).
            $table->timestamp('last_movement_at')->default(DB::raw("'2000-01-01 00:00:00'"))->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'current_icao']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passengers');
    }
};
