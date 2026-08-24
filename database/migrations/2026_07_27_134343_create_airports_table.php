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
        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('icao')->unique();
            $table->string('iata')->nullable();
            $table->string('name');
            $table->decimal('lat', 10, 6);
            $table->decimal('lon', 10, 6);
            $table->string('fir_code')->nullable();
            $table->boolean('is_vatpac')->default(false)->index();
            $table->boolean('is_pseudo')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
