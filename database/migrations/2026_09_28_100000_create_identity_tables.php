<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Role grants are never deleted: revocation sets revoked_at, keeping a full history.
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();
            $table->index(['user_id', 'role', 'revoked_at']);
            $table->index(['role', 'revoked_at']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('name')->nullable();
            $table->json('roles');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('staff_application_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(0);
            $table->timestamps();
            $table->index(['email', 'accepted_at', 'revoked_at']);
        });

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('job_title')->nullable();
            $table->boolean('is_affiliate')->default(false);
            $table->string('affiliate_firm')->nullable();
            $table->string('location')->nullable();
            $table->string('professional_category')->nullable();
            $table->json('practice_areas')->nullable();
            $table->text('professional_registration')->nullable();
            $table->text('qualifications')->nullable();
            // Set only by firm staff after checking credentials; never implied by an application.
            $table->timestamp('credentials_verified_at')->nullable();
            $table->foreignId('credentials_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('credentials_verification_note')->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('type', 20); // individual | organisation
            $table->string('display_name');
            $table->string('organisation_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->char('preferred_currency', 3)->default('NGN');
            $table->text('address')->nullable();
            $table->foreignId('relationship_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('display_name');
        });

        // Portal users authorised to act for a client (an organisation may have several contacts).
        Schema::create('client_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('relationship', 30)->default('owner'); // owner | authorised_contact
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_user');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('user_roles');
    }
};
