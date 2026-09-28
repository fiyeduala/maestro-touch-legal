<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('status', 30)->index();
            $table->string('full_name');
            $table->string('email')->index();
            $table->string('phone', 40);
            $table->string('location');
            $table->string('professional_category', 60);
            $table->json('practice_areas');
            $table->unsignedTinyInteger('years_experience');
            $table->text('qualifications');
            $table->text('professional_registration')->nullable();
            $table->text('statement')->nullable();
            $table->timestamp('consent_at');
            $table->string('consent_version', 40);
            // Lets the applicant respond to information requests or withdraw without an account.
            $table->string('applicant_token_hash', 64)->unique();
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->string('approved_role', 40)->nullable();
            $table->foreignId('invitation_id')->nullable()->constrained('invitations')->nullOnDelete();
            $table->string('submitted_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('staff_application_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_application_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // cv | supporting
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('staff_application_event_id')->nullable();
            $table->timestamp('created_at');
        });

        // Timeline: status changes, internal notes, information requests and applicant responses.
        Schema::create('staff_application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // submitted | status_changed | note | info_requested | applicant_responded
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_internal')->default(true);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_application_events');
        Schema::dropIfExists('staff_application_files');
        Schema::dropIfExists('staff_applications');
    }
};
