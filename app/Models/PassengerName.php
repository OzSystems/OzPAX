<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassengerName extends Model
{
    protected $fillable = [
        'full_name',
        'gender',
        'nationality',
        'source',
        'in_use',
    ];

    protected $casts = [
        'in_use' => 'boolean',
    ];
}
