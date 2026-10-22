<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\IncidentCategory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['PNP' => 'Police / Crime', 'FIRE' => 'Fire Department', 'MDRRMO' => 'Disaster and Emergency'] as $type => $label) {
            $a = Agency::firstOrCreate(['type' => $type], ['name' => $label]);
            IncidentCategory::firstOrCreate(['name' => $label], ['agency_id' => $a->id]);
        }
    }
}
