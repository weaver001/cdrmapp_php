<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_pages', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->string('eyebrow', 120)->nullable();
            $table->string('heading', 180);
            $table->text('introduction')->nullable();
            $table->string('form_heading', 180)->nullable();
            $table->text('form_introduction')->nullable();
            $table->timestamps();
        });

        // Frozen defaults; future edits belong to the CMS, not catalog seeding.
        $pages = [
            'services' => ['Services', 'Explore our community safety and emergency support services.'],
            'announcements' => ['Announcements', 'Stay informed with official community notices and updates.'],
            'team-members' => ['Our team', 'Meet the people working together for a safer community.'],
            'faqs' => ['FAQs', 'Find answers to common questions about our services and incident reporting.'],
            'contact-details' => ['Contact', 'Find official contact information and send us a non-emergency message.'],
        ];
        foreach ($pages as $type => [$heading, $introduction]) {
            DB::table('public_pages')->insert([
                'type' => $type, 'eyebrow' => 'Citizen disaster response', 'heading' => $heading, 'introduction' => $introduction,
                'form_heading' => $type === 'contact-details' ? 'Send a non-emergency message' : null,
                'form_introduction' => $type === 'contact-details' ? 'Do not use this form to report an urgent emergency.' : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_pages');
    }
};
