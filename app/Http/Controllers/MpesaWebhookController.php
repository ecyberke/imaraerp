<?php

namespace App\Http\Controllers;

use App\Services\MpesaService;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated - Safaricom calls this directly, it can't carry
 * a Sanctum bearer token. Correlation back to a tenant happens entirely
 * through CheckoutRequestID (see MpesaService::handleCallback()), and the
 * handler only ever acts on a still-'pending' MpesaStkRequest, so a
 * replayed/duplicate callback (Safaricom retries on anything but a 200)
 * is a safe no-op. Always returns 200 - a non-200 here just makes
 * Safaricom retry the same callback, it doesn't signal anything useful
 * back to them, and failing loudly to an untrusted caller leaks nothing
 * we want attackers probing this endpoint to learn either way.
 */
class MpesaWebhookController extends Controller
{
    public function __construct(private MpesaService $mpesa) {}

    public function callback(Request $request)
    {
        $this->mpesa->handleCallback($request->all());

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
