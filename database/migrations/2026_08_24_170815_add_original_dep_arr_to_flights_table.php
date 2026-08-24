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
            // The as-observed VATSIM dep/arr, preserved even when dep/arr get
            // rerouted to a curated international_destinations entry.
            $table->string('original_dep')->nullable()->after('arr');
            $table->string('original_arr')->nullable()->after('original_dep');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropColumn(['original_dep', 'original_arr']);
        });
    }
};
