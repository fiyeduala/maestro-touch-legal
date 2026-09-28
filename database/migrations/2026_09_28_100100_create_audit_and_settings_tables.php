<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only from the application: the AuditEvent model refuses updates and deletes,
        // and no UI offers them. Database owners can still alter rows (documented in ARCHITECTURE §6).
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', 6)->index();
            $table->foreignId('actor_id')->nullable()->index();
            $table->string('actor_type', 20); // user | system | guest
            $table->string('actor_name')->nullable(); // snapshot, survives user renames
            $table->json('actor_roles')->nullable();
            $table->string('action', 80)->index();
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('summary', 500);
            $table->json('changes')->nullable();
            $table->json('context')->nullable(); // ip, user agent, request id, route, preview flag
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_events');
    }
};
