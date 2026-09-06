<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Passenger extends Model
{
    protected $fillable = [
        'name_id',
        'origin_icao',
        'destination_icao',
        'current_icao',
        'status',
        'boarded_flight_session_id',
        'generated_at',
        'last_movement_at',
        'expires_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'last_movement_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function name(): BelongsTo
    {
        return $this->belongsTo(PassengerName::class, 'name_id');
    }

    public function boardedSession(): BelongsTo
    {
        return $this->belongsTo(FlightSession::class, 'boarded_flight_session_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(PassengerFlightHistory::class);
    }
}
