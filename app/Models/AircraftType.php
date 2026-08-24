<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AircraftType extends Model
{
    // Used when a filed aircraft type has no seeded row.
    public const FALLBACK_MAX_PAX = 150;

    protected $fillable = [
        'icao_type',
        'name',
        'max_pax',
        'is_estimate',
    ];

    protected $casts = [
        'max_pax' => 'integer',
        'is_estimate' => 'boolean',
    ];
}
