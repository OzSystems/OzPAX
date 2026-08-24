<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flight extends Model
{
    protected $fillable = [
        'cid',
        'callsign',
        'dep',
        'arr',
        'original_dep',
        'original_arr',
        'aircraft_icao',
        'logon_time',
        'departed_at',
        'connected_on_ground',
        'landed_at',
    ];

    protected $casts = [
        'logon_time' => 'datetime',
        'departed_at' => 'datetime',
        'connected_on_ground' => 'boolean',
        'landed_at' => 'datetime',
    ];
}
