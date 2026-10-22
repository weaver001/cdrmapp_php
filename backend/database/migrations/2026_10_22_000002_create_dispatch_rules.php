<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_rules', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('category_id')->constrained('incident_categories')->restrictOnDelete();
            $t->foreignId('agency_id')->constrained()->restrictOnDelete();
            $t->decimal('south', 10, 7);
            $t->decimal('north', 10, 7);
            $t->decimal('west', 11, 7);
            $t->decimal('east', 11, 7);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['category_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_rules');
    }
};
