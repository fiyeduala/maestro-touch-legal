<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Billing\PaystackPayments;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Paystack event notifications. The raw body is checked against the x-paystack-signature header
 * before anything is read from it, and the transaction is then verified with Paystack itself.
 * A 500 on an unexpected error makes Paystack retry the delivery.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaystackPayments $paystack): JsonResponse
    {
        try {
            $outcome = $paystack->handleWebhook($request->getContent(), $request->header('x-paystack-signature'));
        } catch (\Throwable $e) {
            Log::error('Paystack webhook could not be processed: '.$e::class);
            report($e);

            return response()->json(['ok' => false], 500);
        }

        return $outcome === 'invalid_signature'
            ? response()->json(['ok' => false], 401)
            : response()->json(['ok' => true]);
    }
}
