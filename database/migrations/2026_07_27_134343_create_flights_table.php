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
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cid');
            $table->string('callsign');
            $table->string('dep')->index();
            $table->string('arr')->index();
            $table->string('aircraft_icao')->nullable();
            // Explicit default (never actually used - the app always
            // supplies a real value) purely to stop MariaDB's legacy
            // behavior of auto-assigning "DEFAULT CURRENT_TIMESTAMP ON
            // UPDATE CURRENT_TIMESTAMP" to the first NOT NULL TIMESTAMP
            // column with no explicit default - which would otherwise
            // silently reset this column to "now" on every update.
            $table->timestamp('logon_time')->default(DB::raw("'2000-01-01 00:00:00'"));
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('landed_at')->nullable();
            $table->timestamps();

            $table->unique(['cid', 'callsign', 'logon_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
