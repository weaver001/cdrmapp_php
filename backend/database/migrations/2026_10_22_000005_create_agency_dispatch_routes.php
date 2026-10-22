<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_dispatch_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        foreach (DB::table('agencies')->whereIn('type', ['FIRE', 'PNP', 'MDRRMO'])->get() as $agency) {
            DB::table('agency_dispatch_routes')->insert([
                'agency_id' => $agency->id, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Preserve legacy rectangle rules for rollback/history; new routing ignores them.
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_dispatch_routes');
    }
};
