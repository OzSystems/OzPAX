<?php

namespace Database\Seeders;

use App\Models\AircraftType;
use Illuminate\Database\Seeder;

class AircraftTypeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = collect(AircraftType::TYPICAL_MAX_PAX)->map(fn ($attrs, $icaoType) => [
            'icao_type' => $icaoType,
            'name' => $attrs['name'],
            'max_pax' => $attrs['max_pax'],
            'is_estimate' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        AircraftType::upsert($rows, ['icao_type'], ['name', 'max_pax', 'is_estimate', 'updated_at']);
    }
}
