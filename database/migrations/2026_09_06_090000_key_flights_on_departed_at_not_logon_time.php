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
        Schema::table('flights', function (Blueprint $table) {
            // logon_time is per VATSIM connection, not per flight plan - a
            // pilot flying several legs (e.g. an out-and-back) without ever
            // disconnecting keeps the same logon_time throughout, so keying
            // uniqueness on it meant only the first leg of any such session
            // was ever actually recorded (every later leg's
            // Flight::firstOrCreate() silently matched the first leg's row
            // instead of inserting its own). departed_at is unique per
            // actual witnessed takeoff, so it correctly distinguishes each
            // completed flight plan regardless of how many legs share one
            // underlying connection.
            $table->dropUnique(['cid', 'callsign', 'logon_time']);
            $table->unique(['cid', 'callsign', 'departed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropUnique(['cid', 'callsign', 'departed_at']);
            $table->unique(['cid', 'callsign', 'logon_time']);
        });
    }
};
