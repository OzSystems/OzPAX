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
        Schema::table('flight_sessions', function (Blueprint $table) {
            $table->boolean('departed_on_ground')->default(false)->after('departed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_sessions', function (Blueprint $table) {
            $table->dropColumn('departed_on_ground');
        });
    }
};
