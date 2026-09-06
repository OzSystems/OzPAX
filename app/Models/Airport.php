<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Airport extends Model
{
    protected $fillable = [
        'icao',
        'iata',
        'name',
        'lat',
        'lon',
        'altitude',
        'altitude_checked_at',
        'fir_code',
        'is_vatpac',
        'is_international_gateway',
        'is_pseudo',
        'movements_8w',
        'tier',
        'tier_calculated_at',
    ];

    protected $casts = [
        'lat' => 'decimal:6',
        'lon' => 'decimal:6',
        'altitude' => 'integer',
        'altitude_checked_at' => 'datetime',
        'is_vatpac' => 'boolean',
        'is_international_gateway' => 'boolean',
        'is_pseudo' => 'boolean',
        'movements_8w' => 'integer',
        'tier' => 'integer',
        'tier_calculated_at' => 'datetime',
    ];
}
