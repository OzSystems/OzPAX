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
            // A VATPAC airport that actually operates international
            // services (customs/immigration) - see
            // TrafficGraphService::computeEdgeWeights for why this gates
            // whether a leg into/out of a curated international_destinations
            // hub counts toward passenger routing at all.
            $table->boolean('is_international_gateway')->default(false)->index()->after('is_vatpac');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->dropColumn('is_international_gateway');
        });
    }
};
