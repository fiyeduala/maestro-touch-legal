<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time recording (D53). Staff record minutes on a matter; finance turns billable entries into fee lines
 * on a draft invoice at the staff member's hourly rate in the invoice currency. Rates are integer minor
 * units per hour, one row per currency, optionally specific to one matter. There is no conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained()->cascadeOnDelete(); // null = the default for every matter
            $table->char('currency', 3);
            $table->unsignedBigInteger('hourly_minor');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'currency']);
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // who did the work
            $table->date('worked_on');
            $table->unsignedInteger('minutes')->nullable(); // null while a timer is running
            $table->timestamp('started_at')->nullable(); // set only while a timer is running
            $table->string('description', 300)->default('');
            $table->boolean('billable')->default(true);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete(); // set while on a draft or issued invoice
            $table->unsignedBigInteger('billed_rate_minor')->nullable(); // the hourly rate used on that invoice
            $table->unsignedBigInteger('billed_minor')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['matter_id', 'worked_on']);
            $table->index(['user_id', 'worked_on']);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('time_entry_id')->nullable()->index()->after('expense_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['time_entry_id']);
            $table->dropColumn('time_entry_id');
        });
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('billing_rates');
    }
};
