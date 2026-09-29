<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing (spec §10). Every financial row has one currency and integer minor units (kobo/cents).
 * Issued invoices, allocations, credit notes and client-fund entries are never edited or deleted;
 * corrections are new rows (credit notes, negative allocations, reversal entries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('replaces_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->char('currency', 3);
            $table->string('title');
            $table->string('status', 20)->index(); // draft | issued | partially_paid | paid | credited | cancelled
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->unsignedBigInteger('fees_minor')->default(0);
            $table->unsignedBigInteger('expenses_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0); // sum of allocations; kept in step inside the same transaction
            $table->unsignedBigInteger('credited_minor')->default(0);
            $table->text('notes')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // fee | expense | tax
            $table->string('description', 300);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('expense_id')->nullable()->index();
            $table->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('description', 300);
            $table->date('incurred_on');
            $table->boolean('billable')->default(true);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete(); // set while on a draft or issued invoice
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // also the receipt number once succeeded
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete(); // the invoice it was made against; null = retainer/credit
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor'); // expected (checkout) or claimed (transfer)
            $table->unsignedBigInteger('received_minor')->nullable(); // confirmed by the provider or a finance officer
            $table->char('received_currency', 3)->nullable(); // as reported; a mismatch is never converted
            $table->string('method', 20); // paystack | bank_transfer | cash | other
            $table->string('status', 24)->index(); // pending | pending_verification | succeeded | failed | abandoned | rejected | needs_review | reversed
            $table->string('provider_reference', 100)->nullable()->unique();
            $table->string('provider_transaction_id', 50)->nullable();
            $table->string('channel', 30)->nullable();
            $table->string('gateway_response', 255)->nullable();
            $table->string('bank_reference', 100)->nullable()->index();
            $table->date('received_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('unapplied_minor')->default(0); // received but not allocated to an invoice (client credit)
            $table->unsignedBigInteger('refunded_minor')->default(0); // confirmed refunds only
            $table->unsignedBigInteger('refund_pending_minor')->default(0);
            $table->string('refund_status', 12)->nullable(); // pending | processed | failed
            $table->string('dispute_status', 40)->nullable();
            $table->string('review_reason', 255)->nullable(); // set when a person must look at it (mismatch, overpaid, duplicate, dispute)
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name', 160)->nullable();
            $table->string('evidence_mime', 100)->nullable();
            $table->unsignedInteger('evidence_size')->nullable();
            $table->string('evidence_sha256', 64)->nullable()->index();
            $table->text('client_note')->nullable();
            $table->text('staff_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete(); // client user
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // staff
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        // Signed amounts: a reversal or refund is a negative row, never an edit.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('kind', 12); // payment | credit | reversal | refund
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // Everything Paystack told us (webhooks, callback and reconciliation checks), for idempotency and review.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('source', 12); // webhook | callback | reconcile
            $table->string('event', 60);
            $table->string('reference', 100)->nullable()->index();
            $table->string('event_key', 64)->nullable()->unique(); // webhooks only: repeated deliveries are ignored
            $table->boolean('signature_valid')->nullable();
            $table->json('payload')->nullable(); // a minimal subset; never card or customer details beyond the email
            $table->string('outcome', 60);
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // Money held for clients (e.g. recovered funds). Not firm revenue. Entries are never edited;
        // a mistake is corrected by a reversal entry.
        Schema::create('client_fund_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3);
            $table->string('type', 20); // receipt | fee_deduction | remittance | reversal
            $table->bigInteger('amount_minor'); // + money in, − money out
            $table->date('occurred_on');
            $table->string('description', 500);
            $table->string('counterparty', 255)->nullable();
            $table->string('bank_reference', 100)->nullable();
            $table->text('authorisation')->nullable(); // required for fee deductions and remittances
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('client_fund_entries')->nullOnDelete();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name', 160)->nullable();
            $table->string('evidence_mime', 100)->nullable();
            $table->unsignedInteger('evidence_size')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_id', 'currency']);
        });

        // A check of the client-funds bank account against the ledger on a date.
        Schema::create('client_fund_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('statement_date');
            $table->bigInteger('statement_balance_minor');
            $table->bigInteger('ledger_balance_minor');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_fund_reconciliations');
        Schema::dropIfExists('client_fund_entries');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
