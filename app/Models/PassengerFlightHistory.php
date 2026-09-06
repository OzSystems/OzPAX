<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassengerFlightHistory extends Model
{
    protected $table = 'passenger_flight_history';

    protected $fillable = [
        'passenger_id',
        'flight_id',
        'callsign',
        'dep',
        'arr',
        'aircraft_icao',
        'departed_at',
        'landed_at',
    ];

    protected $casts = [
        'departed_at' => 'datetime',
        'landed_at' => 'datetime',
    ];

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
