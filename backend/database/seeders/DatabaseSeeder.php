<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\IncidentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach (Agency::DEFAULT_NAMES as $type => $label) {
                $agency = Agency::firstOrCreate(['type' => $type], ['name' => $label]);
                $agency->update(['name' => $label]);
                IncidentCategory::where('agency_id', $agency->id)
                    ->whereIn('name', Agency::LEGACY_CATEGORY_NAMES[$type])
                    ->update(['name' => $label]);
                IncidentCategory::firstOrCreate(['agency_id' => $agency->id, 'name' => $label]);
            }
        });
    }
}
