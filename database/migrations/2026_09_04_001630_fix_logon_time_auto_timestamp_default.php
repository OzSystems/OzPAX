<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `flight_sessions.logon_time` and `flights.logon_time` were both declared
 * as `$table->timestamp('logon_time')` - NOT NULL, no explicit default, and
 * (critically) the first such TIMESTAMP column in each table. On this
 * MariaDB install, `explicit_defaults_for_timestamp` is off, so a NOT NULL
 * TIMESTAMP column declared without an explicit default automatically gets
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` if it's the first
 * one in the table. Every other timestamp column here is nullable and so
 * escaped this, but logon_time didn't - meaning MySQL silently reset it to
 * "now" on every single UPDATE, regardless of what value the application
 * tried to save. Since logon_time is part of the (cid, callsign, logon_time)
 * key RecordVatsimFlights uses to find an existing session/flight, that
 * silent reset broke the match on the very next poll - creating a brand
 * new duplicate row instead of updating the same one (visible on the live
 * map as a trail of frozen aircraft positions rather than one moving icon).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE flight_sessions MODIFY logon_time TIMESTAMP NOT NULL DEFAULT '2000-01-01 00:00:00'");
        DB::statement("ALTER TABLE flights MODIFY logon_time TIMESTAMP NOT NULL DEFAULT '2000-01-01 00:00:00'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE flight_sessions MODIFY logon_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE flights MODIFY logon_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }
};
