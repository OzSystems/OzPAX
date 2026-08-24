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
        Schema::create('flight_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cid');
            $table->string('callsign');
            $table->timestamp('logon_time');
            $table->string('dep')->nullable();
            $table->string('arr')->nullable();
            $table->string('aircraft_icao')->nullable();
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lon', 10, 6)->nullable();
            $table->integer('altitude')->nullable();
            $table->integer('groundspeed')->nullable();
            $table->integer('heading')->nullable();
            $table->boolean('relevant')->default(false);
            $table->string('status')->default('airborne');
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['cid', 'callsign', 'logon_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_sessions');
    }
};
