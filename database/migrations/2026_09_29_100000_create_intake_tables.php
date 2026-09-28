<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Services the firm handles; public ones are offered on the legal-assistance form.
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name');
            $table->text('summary')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->json('stages')->nullable(); // [{key, label}]; null = default matter stages
            $table->timestamps();
        });

        // Intake forms are versioned: an enquiry keeps the exact version (and labels) it answered.
        Schema::create('intake_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('fields'); // [{key, label, type, required, options, help}]
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['service_id', 'version']);
        });

        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('status', 30)->index();
            $table->string('source', 30); // website | portal | phone | whatsapp | email | referral | walk_in | other
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('intake_form_id')->nullable()->constrained()->nullOnDelete();
            $table->json('answers')->nullable(); // [{key, label, value}] snapshot
            $table->text('summary');
            $table->text('preferred_times')->nullable();
            $table->string('contact_name');
            $table->string('contact_email')->index();
            $table->string('contact_phone', 40)->nullable();
            $table->string('organisation_name')->nullable();
            $table->string('country', 2)->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // staff-entered
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('follow_up_on')->nullable();
            $table->string('conflict_status', 20)->default('pending'); // pending | cleared | flagged
            $table->timestamp('conflict_reviewed_at')->nullable();
            $table->text('closure_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->unsignedBigInteger('matter_id')->nullable()->index();
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_version', 40)->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'status']);
        });

        Schema::create('enquiry_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('body')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // People and organisations connected to an enquiry; they move to the matter on conversion.
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('matter_id')->nullable()->index();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('role', 30); // client | opposing | related | other
            $table->text('notes')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A human decision, never automatic. The searched names and suggestions shown are kept as evidence.
        Schema::create('conflict_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 20); // cleared | flagged
            $table->text('reason');
            $table->json('searched');
            $table->json('suggestions');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conflict_reviews');
        Schema::dropIfExists('parties');
        Schema::dropIfExists('enquiry_events');
        Schema::dropIfExists('enquiries');
        Schema::dropIfExists('intake_forms');
        Schema::dropIfExists('services');
    }
};
