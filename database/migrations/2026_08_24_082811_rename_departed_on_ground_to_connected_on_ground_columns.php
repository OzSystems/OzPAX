<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MariaDB < 10.5.2 doesn't support the `RENAME COLUMN` shorthand, so
        // CHANGE is used instead (which requires restating the column type).
        DB::statement('ALTER TABLE flight_sessions CHANGE departed_on_ground connected_on_ground TINYINT(1) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE flights CHANGE departed_on_ground connected_on_ground TINYINT(1) NOT NULL DEFAULT 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE flight_sessions CHANGE connected_on_ground departed_on_ground TINYINT(1) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE flights CHANGE connected_on_ground departed_on_ground TINYINT(1) NOT NULL DEFAULT 0');
    }
};
