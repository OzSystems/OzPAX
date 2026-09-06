<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AircraftType extends Model
{
    // Used when a filed aircraft type has no seeded row.
    public const FALLBACK_MAX_PAX = 150;

    /**
     * Approximate typical max passenger capacity per ICAO type, covering the
     * aircraft commonly seen filed on VATSIM in Australian/Oceania airspace.
     * Values are representative single-class-ish figures, not exact per-
     * airline configurations - real filed types vary by operator fit-out.
     *
     * Consulted by RecordVatsimFlights::resolveAircraftType() before it ever
     * falls back to AircraftEnginesDatabaseClient's external lookup - that
     * dataset reports each type's certified maximum EXIT-LIMIT seat count
     * (a regulatory ceiling almost no airline actually configures to, e.g.
     * 853 for an A380 against every real operator's 470-620), which massively
     * overstates realistic capacity for any type it covers, especially
     * widebodies. It remains useful only as a last-resort guess for a type
     * this list doesn't have an entry for. Shared with AircraftTypeSeeder so
     * the two are never allowed to drift apart.
     */
    public const TYPICAL_MAX_PAX = [
        // Regional / GA
        'C172' => ['name' => 'Cessna 172', 'max_pax' => 3],
        'C182' => ['name' => 'Cessna 182', 'max_pax' => 3],
        'C208' => ['name' => 'Cessna 208 Caravan', 'max_pax' => 9],
        'PA28' => ['name' => 'Piper PA-28', 'max_pax' => 3],
        'PA31' => ['name' => 'Piper PA-31 Navajo', 'max_pax' => 7],
        'PA44' => ['name' => 'Piper PA-44 Seminole', 'max_pax' => 3],
        'SR22' => ['name' => 'Cirrus SR22', 'max_pax' => 3],
        'BE20' => ['name' => 'Beechcraft King Air 200', 'max_pax' => 9],
        'BE9L' => ['name' => 'Beechcraft King Air 90', 'max_pax' => 6],
        'GA8' => ['name' => 'GippsAero GA8 Airvan', 'max_pax' => 7],

        // Turboprop regional
        'AT72' => ['name' => 'ATR 72', 'max_pax' => 70],
        'AT76' => ['name' => 'ATR 72-600', 'max_pax' => 70],
        'AT45' => ['name' => 'ATR 42', 'max_pax' => 48],
        'AT46' => ['name' => 'ATR 42-600', 'max_pax' => 48],
        'DH8A' => ['name' => 'Dash 8-100', 'max_pax' => 37],
        'DH8B' => ['name' => 'Dash 8-200', 'max_pax' => 37],
        'DH8C' => ['name' => 'Dash 8-300', 'max_pax' => 50],
        'DH8D' => ['name' => 'Dash 8 Q400', 'max_pax' => 78],
        'SF34' => ['name' => 'Saab 340', 'max_pax' => 34],
        'B350' => ['name' => 'Beechcraft King Air 350', 'max_pax' => 11],

        // Regional jet
        'E170' => ['name' => 'Embraer E170', 'max_pax' => 76],
        'E175' => ['name' => 'Embraer E175', 'max_pax' => 88],
        'E190' => ['name' => 'Embraer E190', 'max_pax' => 106],
        'E195' => ['name' => 'Embraer E195', 'max_pax' => 124],
        'CRJ2' => ['name' => 'Bombardier CRJ200', 'max_pax' => 50],
        'CRJ7' => ['name' => 'Bombardier CRJ700', 'max_pax' => 70],
        'CRJ9' => ['name' => 'Bombardier CRJ900', 'max_pax' => 90],
        'CRJX' => ['name' => 'Bombardier CRJ1000', 'max_pax' => 104],
        'BCS1' => ['name' => 'Airbus A220-100', 'max_pax' => 125],
        'BCS3' => ['name' => 'Airbus A220-300', 'max_pax' => 145],

        // Narrowbody
        'A319' => ['name' => 'Airbus A319', 'max_pax' => 140],
        'A320' => ['name' => 'Airbus A320', 'max_pax' => 180],
        'A321' => ['name' => 'Airbus A321', 'max_pax' => 220],
        'A20N' => ['name' => 'Airbus A320neo', 'max_pax' => 180],
        'A21N' => ['name' => 'Airbus A321neo', 'max_pax' => 220],
        'B737' => ['name' => 'Boeing 737-700', 'max_pax' => 140],
        'B738' => ['name' => 'Boeing 737-800', 'max_pax' => 189],
        'B739' => ['name' => 'Boeing 737-900', 'max_pax' => 220],
        'B38M' => ['name' => 'Boeing 737 MAX 8', 'max_pax' => 189],
        'B39M' => ['name' => 'Boeing 737 MAX 9', 'max_pax' => 220],

        // Widebody
        'A332' => ['name' => 'Airbus A330-200', 'max_pax' => 278],
        'A333' => ['name' => 'Airbus A330-300', 'max_pax' => 300],
        'A339' => ['name' => 'Airbus A330-900neo', 'max_pax' => 300],
        'A346' => ['name' => 'Airbus A340-600', 'max_pax' => 380],
        'A359' => ['name' => 'Airbus A350-900', 'max_pax' => 325],
        'A35K' => ['name' => 'Airbus A350-1000', 'max_pax' => 366],
        'A388' => ['name' => 'Airbus A380-800', 'max_pax' => 500],
        'B703' => ['name' => 'Boeing 707', 'max_pax' => 140],
        'B742' => ['name' => 'Boeing 747-200', 'max_pax' => 400],
        'B744' => ['name' => 'Boeing 747-400', 'max_pax' => 416],
        'B748' => ['name' => 'Boeing 747-8', 'max_pax' => 410],
        'B763' => ['name' => 'Boeing 767-300ER', 'max_pax' => 250],
        'B772' => ['name' => 'Boeing 777-200', 'max_pax' => 314],
        'B77L' => ['name' => 'Boeing 777-200LR', 'max_pax' => 317],
        'B77W' => ['name' => 'Boeing 777-300ER', 'max_pax' => 396],
        'B788' => ['name' => 'Boeing 787-8', 'max_pax' => 242],
        'B789' => ['name' => 'Boeing 787-9', 'max_pax' => 296],
        'B78X' => ['name' => 'Boeing 787-10', 'max_pax' => 330],

        // Freighters (typically 0-2 seats for crew/loadmasters, not payload pax)
        'B77F' => ['name' => 'Boeing 777F', 'max_pax' => 2],
        'B74F' => ['name' => 'Boeing 747-400F', 'max_pax' => 2],
    ];

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
