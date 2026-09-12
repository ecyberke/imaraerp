<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Architecture §1.1/§3.10: "idempotency-key handling built into the
 * webhook-receiving infrastructure itself (even though M-Pesa/TalkSasa
 * integration is Phase 3, the infrastructure for it isn't)" - and
 * frontend financial-mutation requests get "a request-level idempotency
 * token" too. Generic and reusable: any mutation route (DummyRecord's
 * store today, every real financial-entity write from ledger-core onward,
 * eventually inbound webhooks) applies this the same way.
 *
 * Opt-in on the caller's side (no Idempotency-Key header -> passes
 * through unchanged) - not every internal call needs it, and making it
 * mandatory here would break every route that doesn't send one.
 *
 * Concurrency: the real race - two requests with the same key arriving
 * at the same instant - is resolved by the table's own
 * unique(tenant_id, key) constraint, not an application-level lock held
 * across arbitrary downstream work (which could itself be slow or open
 * its own transactions). Whichever request's INSERT wins proceeds;
 * the other's INSERT fails the unique constraint and is treated as a
 * duplicate-in-progress, the same response a genuinely-in-flight
 * original request gets.
 */
class EnsureIdempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || ! $request->user()) {
            return $next($request);
        }

        $tenantId = $request->user()->tenant_id;

        $existing = IdempotencyKey::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();

        if ($existing) {
            return $this->duplicateResponse($existing);
        }

        try {
            $record = IdempotencyKey::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'key' => $key,
                'endpoint' => $request->path(),
                'response_payload' => null,
            ]);
        } catch (QueryException) {
            // Lost the race to another request with the same key.
            $existing = IdempotencyKey::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('key', $key)->first();

            return $existing ? $this->duplicateResponse($existing) : $next($request);
        }

        $response = $next($request);

        $record->update([
            'response_payload' => [
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), true),
            ],
        ]);

        return $response;
    }

    private function duplicateResponse(IdempotencyKey $record): Response
    {
        if ($record->response_payload !== null) {
            return response()->json(
                $record->response_payload['body'],
                $record->response_payload['status'],
            );
        }

        // The original request with this key is still in flight.
        return response()->json(['message' => 'Request already in progress.'], 409);
    }
}
