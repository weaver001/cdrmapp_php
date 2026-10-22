<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This frozen mapping also upgrades already-deployed databases without changing record IDs.
        $catalog = [
            'FIRE' => ['Fire Department (BFP)', ['Fire Department', 'Fire Department (BFP)']],
            'PNP' => ['Police Department (PNP)', ['Police / Crime', 'Police Department (PNP)']],
            'MDRRMO' => ['MDRRMO', ['Disaster and Emergency', 'MDRRMO']],
        ];
        DB::transaction(function () use ($catalog) {
            foreach ($catalog as $type => [$label, $legacyNames]) {
                $agency = DB::table('agencies')->where('type', $type)->first();
                if ($agency) {
                    $agencyId = $agency->id;
                    DB::table('agencies')->where('id', $agencyId)->update(['name' => $label, 'updated_at' => now()]);
                } else {
                    $agencyId = DB::table('agencies')->insertGetId(['type' => $type, 'name' => $label, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('incident_categories')->where('agency_id', $agencyId)->whereIn('name', $legacyNames)
                    ->update(['name' => $label, 'updated_at' => now()]);
                if (! DB::table('incident_categories')->where('agency_id', $agencyId)->where('name', $label)->exists()) {
                    DB::table('incident_categories')->insert(['agency_id' => $agencyId, 'name' => $label, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Keep operational records and references intact when rolling back schema changes.
    }
};
