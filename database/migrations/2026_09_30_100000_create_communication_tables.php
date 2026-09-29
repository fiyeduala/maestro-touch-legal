<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 4: matter conversations, daily digests, delivery records and consultations.
| Messages are never edited or deleted; corrections are follow-up messages or audited amendments.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            // client: the private client–firm conversation. internal: staff-only notes, never shown to clients.
            $table->string('audience', 10);
            $table->string('kind', 20)->default('message'); // message | call_note | amendment
            $table->string('channel', 20)->nullable(); // call_note only: phone | whatsapp | meeting | other
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('sender_is_client')->default(false);
            $table->text('body');
            $table->foreignId('amends_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['matter_id', 'audience', 'id']);
            $table->index(['audience', 'created_at']);
        });

        Schema::create('message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('read_at');
            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'message_id']);
        });

        // When a "you have a new message" email last went to a user for a matter (avoids one email per message).
        Schema::create('message_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_message_id');
            $table->dateTime('sent_at');
            $table->unique(['matter_id', 'user_id']);
        });

        // Digest preference disclosed at onboarding: full conversation text (default) or counts only.
        Schema::table('client_user', function (Blueprint $table) {
            $table->string('digest_mode', 10)->default('full');
        });
        Schema::table('matters', function (Blueprint $table) {
            $table->string('digest_mode', 10)->nullable(); // null: full, except restricted matters get summary
        });

        Schema::create('digest_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('cutoff_at')->unique();
            $table->dateTime('window_start');
            $table->string('status', 20)->default('planning'); // planning | planned
            $table->unsignedInteger('digest_count')->default(0);
            $table->timestamps();
        });

        Schema::create('digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digest_run_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // client | firm
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 10); // full | summary
            $table->json('message_ids');
            $table->dateTime('window_start');
            $table->dateTime('window_end');
            // pending | sending | sent | failed | skipped | uncertain
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('skipped_reason')->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->timestamps();
            $table->index(['status', 'id']);
        });

        // Outgoing email log. Only metadata: never message bodies.
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->default('mail');
            $table->string('type', 120)->nullable();
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('status', 20); // sent | failed
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
        });

        Schema::create('consultation_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->boolean('is_free')->default(true);
            $table->unsignedBigInteger('fee_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('consultation_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enquiry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact_name');
            $table->string('contact_email');
            $table->foreignId('host_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at'); // UTC
            $table->dateTime('ends_at');
            // requested | confirmed | cancelled | completed | no_show
            $table->string('status', 20)->default('requested');
            $table->string('meeting_url', 500)->nullable();
            $table->text('client_agenda')->nullable();
            $table->text('outcome')->nullable(); // internal
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('reminders_sent')->nullable(); // hours thresholds already sent for this start time
            $table->unsignedInteger('sequence')->default(0); // calendar invitation revision
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });

        // One row per lock name; bookings take it FOR UPDATE so concurrent requests are serialised.
        Schema::create('booking_locks', function (Blueprint $table) {
            $table->string('name', 50)->primary();
        });
        DB::table('booking_locks')->insert(['name' => 'consultations']);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_locks');
        Schema::dropIfExists('consultations');
        Schema::dropIfExists('consultation_types');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('digests');
        Schema::dropIfExists('digest_runs');
        Schema::table('matters', fn (Blueprint $table) => $table->dropColumn('digest_mode'));
        Schema::table('client_user', fn (Blueprint $table) => $table->dropColumn('digest_mode'));
        Schema::dropIfExists('message_notices');
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('messages');
    }
};
