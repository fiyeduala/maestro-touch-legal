<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Documents\Documents;
use App\Domain\Operations\Audit;
use App\Http\Controllers\Controller;
use App\Models\ClientFundEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Transfer slips and client-funds evidence from the private disk. Always a download (never rendered
 * in the admin's browser), never cached, and every access is audited.
 */
class BillingEvidenceController extends Controller
{
    /** Payment evidence carries the client's bank details: finance and full administrators only. */
    public function payment(Payment $payment): StreamedResponse
    {
        Gate::authorize('verify', $payment);

        return $this->download($payment->evidence_path, $payment->evidence_name, $payment->evidence_mime, $payment, "Transfer evidence for {$payment->reference} downloaded");
    }

    public function fundEntry(ClientFundEntry $entry): StreamedResponse
    {
        Gate::authorize('view', $entry);

        return $this->download($entry->evidence_path, $entry->evidence_name, $entry->evidence_mime, $entry, "Client-funds evidence for {$entry->reference} downloaded");
    }

    private function download(?string $path, ?string $name, ?string $mime, $subject, string $summary): StreamedResponse
    {
        abort_unless($path && Storage::disk(Documents::DISK)->exists($path), 404);
        Audit::record('billing.evidence_downloaded', $summary, $subject);

        return Storage::disk(Documents::DISK)->download($path, $name ?: basename($path), [
            'Content-Type' => $mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
