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
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cid');
            $table->string('callsign');
            $table->string('dep')->index();
            $table->string('arr')->index();
            $table->string('aircraft_icao')->nullable();
            $table->timestamp('logon_time');
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
