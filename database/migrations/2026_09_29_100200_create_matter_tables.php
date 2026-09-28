<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matters', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('engagement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('client_summary')->nullable(); // shown to the client
            $table->text('internal_assessment')->nullable(); // staff only
            $table->string('jurisdiction')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->string('confidentiality', 20)->default('standard');
            $table->string('stage', 40);
            $table->string('status', 20)->default('open')->index(); // open | on_hold | closed
            $table->string('next_action')->nullable();
            $table->timestamp('next_action_due_at')->nullable();
            $table->boolean('next_action_client_visible')->default(false);
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('representation_started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closing_note')->nullable();
            $table->json('closure_checklist')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });

        // Access to a matter for staff below full administrator comes only from an active row here.
        Schema::create('matter_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // responsible | member
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('added_at')->useCurrent();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 500)->nullable();
            $table->index(['matter_id', 'removed_at']);
            $table->index(['user_id', 'removed_at']);
        });

        // Timeline. client_visible events may appear in the portal; the rest are staff only.
        Schema::create('matter_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('summary', 500);
            $table->boolean('client_visible')->default(false);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['matter_id', 'created_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('depends_on_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('status', 20)->default('open')->index(); // open | in_progress | done | cancelled
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_note')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['assignee_id', 'status', 'due_at']);
        });

        // Milestones and manually entered legal deadlines. Nothing is calculated from statute.
        Schema::create('matter_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // milestone | deadline
            $table->string('title');
            $table->timestamp('due_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->boolean('client_visible')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['matter_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_deadlines');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('matter_events');
        Schema::dropIfExists('matter_team_members');
        Schema::dropIfExists('matters');
    }
};
