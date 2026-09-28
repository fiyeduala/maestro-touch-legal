<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('open'); // open | fulfilled | cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        // Confidential files live on the private "confidential" disk and are served only through authorised routes.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30);
            $table->string('title');
            $table->string('audience', 10)->default('internal'); // internal | client
            $table->boolean('is_deliverable')->default(false);
            // Deliverables: draft | in_review | changes_requested | approved | released. Other files: filed | released.
            $table->string('status', 20);
            $table->unsignedBigInteger('current_version_id')->nullable();
            // The client only ever sees this version, never a later internal draft.
            $table->unsignedBigInteger('released_version_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_decision', 20)->nullable(); // approved | changes_requested
            $table->timestamp('client_decision_at')->nullable();
            $table->boolean('uploaded_by_client')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['matter_id', 'audience']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('disk', 30);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('uploaded_by_client')->default(false);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['document_id', 'version']);
        });

        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->text('body')->nullable();
            $table->boolean('client_visible')->default(false);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_requests');
    }
};
