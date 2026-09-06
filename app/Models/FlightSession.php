<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlightSession extends Model
{
    protected $fillable = [
        'cid',
        'callsign',
        'logon_time',
        'dep',
        'arr',
        'aircraft_icao',
        'lat',
        'lon',
        'altitude',
        'groundspeed',
        'heading',
        'relevant',
        'status',
        'departed_at',
        'connected_on_ground',
        'boarding_locked_at',
        'last_seen_at',
    ];

    protected $casts = [
        'logon_time' => 'datetime',
        'lat' => 'decimal:6',
        'lon' => 'decimal:6',
        'relevant' => 'boolean',
        'departed_at' => 'datetime',
        'connected_on_ground' => 'boolean',
        'boarding_locked_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function boardedPassengers(): HasMany
    {
        return $this->hasMany(Passenger::class, 'boarded_flight_session_id');
    }
}
