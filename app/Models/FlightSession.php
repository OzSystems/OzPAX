<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'departed_on_ground',
        'last_seen_at',
    ];

    protected $casts = [
        'logon_time' => 'datetime',
        'lat' => 'decimal:6',
        'lon' => 'decimal:6',
        'relevant' => 'boolean',
        'departed_at' => 'datetime',
        'departed_on_ground' => 'boolean',
        'last_seen_at' => 'datetime',
    ];
}
