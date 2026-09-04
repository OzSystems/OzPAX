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
            // Total departures + arrivals over the trailing 8-week window,
            // and the tier (1-5) that movement count sorts the airport into
            // - see App\Jobs\CalculateAirportTiers. Tier defaults to 5
            // (lowest) so a never-yet-calculated airport doesn't look busy.
            $table->unsignedInteger('movements_8w')->default(0)->after('is_pseudo');
            $table->unsignedTinyInteger('tier')->default(5)->index()->after('movements_8w');
            $table->timestamp('tier_calculated_at')->nullable()->after('tier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->dropColumn(['movements_8w', 'tier', 'tier_calculated_at']);
        });
    }
};
