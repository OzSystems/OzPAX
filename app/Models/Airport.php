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
        'fir_code',
        'is_vatpac',
        'is_pseudo',
        'movements_8w',
        'tier',
        'tier_calculated_at',
    ];

    protected $casts = [
        'lat' => 'decimal:6',
        'lon' => 'decimal:6',
        'altitude' => 'integer',
        'is_vatpac' => 'boolean',
        'is_pseudo' => 'boolean',
        'movements_8w' => 'integer',
        'tier' => 'integer',
        'tier_calculated_at' => 'datetime',
    ];
}
