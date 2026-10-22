<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Null identifies images uploaded before configurable storage.
            $table->string('image_disk')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contents', fn (Blueprint $table) => $table->dropColumn('image_disk'));
    }
};
