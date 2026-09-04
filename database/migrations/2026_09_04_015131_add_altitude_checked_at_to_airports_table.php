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
        Schema::table('airports', function (Blueprint $table) {
            // Stamped every time RecordVatsimFlights asks Airlabs for this
            // airport's altitude, whether or not Airlabs actually had it -
            // so an airport Airlabs has no data for doesn't get re-queried
            // on every single poll forever.
            $table->timestamp('altitude_checked_at')->nullable()->after('altitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->dropColumn('altitude_checked_at');
        });
    }
};
