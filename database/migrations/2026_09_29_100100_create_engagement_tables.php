<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money is integer minor units (kobo/cents) with one currency per quotation (DECISIONS / ARCHITECTURE §6).
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('matter_id')->nullable()->index();
            $table->char('currency', 3);
            $table->string('title');
            $table->string('status', 20)->index(); // draft | sent | accepted | declined | withdrawn | expired
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('accepted_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A sent version is frozen; changes after sending create a new version.
        Schema::create('quotation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('scope');
            $table->text('exclusions')->nullable();
            $table->json('lines'); // [{kind: fee|expense, description, quantity, unit_minor, amount_minor}]
            $table->json('payment_stages')->nullable(); // [{label, amount_minor, due}]
            $table->unsignedBigInteger('fees_minor')->default(0);
            $table->unsignedBigInteger('expenses_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['quotation_id', 'version']);
        });

        Schema::create('engagement_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('engagements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('matter_id')->nullable()->index();
            $table->foreignId('quotation_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status', 20)->index(); // draft | sent | accepted | declined | withdrawn | approved
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('accepted_version_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('engagement_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('template_id')->nullable()->constrained('engagement_templates')->nullOnDelete();
            $table->unsignedInteger('template_version')->nullable();
            $table->longText('body');
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['engagement_id', 'version']);
        });

        // Evidence of a client decision on one specific version (quotation, engagement terms or a released draft).
        Schema::create('acceptances', function (Blueprint $table) {
            $table->id();
            $table->morphs('acceptable');
            $table->string('decision', 20); // accepted | declined | changes_requested
            $table->string('method', 20); // portal | offline_signed
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // the client user (portal)
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // staff (offline)
            $table->string('signed_name')->nullable();
            $table->string('content_hash', 64);
            $table->text('statement');
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('evidence_document_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acceptances');
        Schema::dropIfExists('engagement_versions');
        Schema::dropIfExists('engagements');
        Schema::dropIfExists('engagement_templates');
        Schema::dropIfExists('quotation_versions');
        Schema::dropIfExists('quotations');
    }
};
