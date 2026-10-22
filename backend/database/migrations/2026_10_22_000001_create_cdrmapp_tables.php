<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type')->unique();
            $t->text('jurisdiction')->nullable();
            $t->string('emergency_phone')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('citizen')->index();
            $t->foreignId('agency_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('date_of_birth')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('active')->default(true);
            $t->string('profile_photo_path')->nullable();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('incident_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('agency_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('incidents', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_reference')->unique();
            $t->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('agency_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->constrained('incident_categories')->restrictOnDelete();
            $t->text('description');
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 11, 7);
            $t->decimal('accuracy_m', 10, 2)->nullable();
            $t->string('location_source');
            $t->timestamp('location_observed_at')->nullable();
            $t->string('address')->nullable();
            $t->timestamp('incident_at')->nullable();
            $t->timestamp('received_at');
            $t->string('workflow_status')->default('submitted');
            $t->string('verification_status')->default('unverified');
            $t->boolean('is_test')->default(false);
            $t->foreignId('assigned_officer_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->timestamps();
            $t->unique(['reporter_id', 'idempotency_key']);
            $t->index(['agency_id', 'workflow_status', 'received_at']);
            $t->index(['verification_status', 'received_at']);
            $t->index(['latitude', 'longitude']);
        });
        Schema::create('incident_status_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('old_status')->nullable();
            $t->string('new_status');
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('incident_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->restrictOnDelete();
            $t->foreignId('officer_id')->constrained('users')->restrictOnDelete();
            $t->text('note');
            $t->string('outcome')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
        });
        Schema::create('incident_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->restrictOnDelete();
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->string('outcome');
            $t->text('rationale');
            $t->timestamps();
        });
        Schema::create('incident_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->restrictOnDelete();
            $t->string('path');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action');
            $t->string('record_type');
            $t->unsignedBigInteger('record_id');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('contents', function (Blueprint $t) {
            $t->id();
            $t->string('type')->index();
            $t->string('title');
            $t->text('body');
            $t->string('image_path')->nullable();
            $t->boolean('published')->default(false)->index();
            $t->json('details')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('contact_messages', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->text('message');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('device_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token', 512)->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['device_tokens', 'notifications', 'contact_messages', 'contents', 'audit_logs', 'incident_attachments', 'incident_verifications', 'incident_responses', 'incident_status_histories', 'incidents', 'incident_categories', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('agency_id');
            $t->dropColumn(['role', 'date_of_birth', 'phone', 'active', 'profile_photo_path']);
        });
        Schema::dropIfExists('agencies');
    }
};
