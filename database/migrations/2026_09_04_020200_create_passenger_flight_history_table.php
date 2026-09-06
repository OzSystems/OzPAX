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
        Schema::create('passenger_flight_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_id')->index();
            $table->foreignId('flight_id')->nullable()->index();
            $table->string('callsign');
            $table->string('dep');
            $table->string('arr');
            $table->string('aircraft_icao')->nullable();
            $table->timestamp('departed_at')->nullable();
            // Explicit default (never actually used) purely to stop
            // MariaDB's legacy behavior of auto-assigning "DEFAULT
            // CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" to the first
            // NOT NULL TIMESTAMP column with no explicit default.
            $table->timestamp('landed_at')->default(DB::raw("'2000-01-01 00:00:00'"));
            $table->timestamps();

            $table->index(['passenger_id', 'landed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passenger_flight_history');
    }
};
