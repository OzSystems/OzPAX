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
        Schema::create('passenger_names', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('gender')->nullable();
            $table->string('nationality', 8)->nullable();
            $table->string('source')->default('randomuser.me');
            // Whether this name is currently assigned to a passenger.
            // Released back to the pool once that passenger's record
            // reaches a terminal state (completed/stranded).
            $table->boolean('in_use')->default(false)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passenger_names');
    }
};
