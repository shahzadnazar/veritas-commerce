<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Actions\RecordWebhookEvent;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Exceptions\ProviderSignatureInvalid;
use App\Modules\Payments\Jobs\ProcessProviderEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The provider's own way in.
 *
 * No session, no CSRF token, no cookie — a provider's server has none of
 * those. The only thing standing between this endpoint and the internet is
 * the signature, so the signature is checked first and nothing at all
 * happens before it passes: an unsigned request is not stored, not queued
 * and not logged with its body.
 *
 * §62's ordering, in three steps and in this order:
 *
 *  1. Verify. A forged payload never becomes a ProviderEvent.
 *  2. Persist, uniquely by (provider, event_id). The row is the record
 *     that this platform received it.
 *  3. Queue the work and return 200.
 *
 * Returning 200 before verifying would tell the provider "received" about
 * something nobody has looked at — the provider stops retrying, and a
 * processing bug becomes a payment that silently never completes. Doing
 * the work inline instead would make the provider's retry timeout the
 * platform's transaction budget.
 *
 * The raw body is read with `getContent()`, never the parsed input:
 * signature verification is over the exact bytes sent, and re-encoding
 * decoded JSON changes them.
 */
final class ProviderWebhookController
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly RecordWebhookEvent $record,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $this->signatureFrom($request);

        try {
            $event = $this->provider->parseEvent($payload, $signature);
        } catch (ProviderSignatureInvalid) {
            /*
             * Deliberately terse. Naming which check failed helps somebody
             * iterate toward passing it, and 400 rather than 401 avoids
             * inviting a retry of something that will never be valid.
             */
            return response('Invalid signature.', 400);
        }

        $stored = ($this->record)($event, substr(hash('sha256', $signature), 0, 32));

        if ($stored === null) {
            /*
             * Already received. 200, because from the provider's point of
             * view this delivery succeeded — and anything else invites a
             * retry loop over an event already being handled.
             */
            return response('Already received.', 200);
        }

        ProcessProviderEvent::dispatch($stored->id);

        return response('Received.', 200);
    }

    /**
     * The signature header, whichever provider sent it.
     *
     * Named per provider rather than accepting any header, so a request
     * carrying the wrong provider's header shape is simply unsigned.
     */
    private function signatureFrom(Request $request): string
    {
        return (string) ($request->header('Stripe-Signature')
            ?? $request->header('X-Veritas-Signature')
            ?? '');
    }
}
