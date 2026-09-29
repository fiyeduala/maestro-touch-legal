<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Manual handoffs to Naija Virtual Notary (DECISIONS D40). No files and no credentials are stored here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notarisation_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->restrictOnDelete();
            $table->string('document_description', 500);
            $table->string('consent_method', 20);
            $table->string('consent_note', 500)->nullable();
            $table->dateTime('consent_recorded_at');
            $table->foreignId('consent_recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('external_reference', 100)->nullable();
            $table->string('status', 20)->default('consented')->index();
            $table->string('status_note', 500)->nullable();
            $table->dateTime('status_changed_at');
            $table->foreignId('status_changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notarisation_handoffs');
    }
};
