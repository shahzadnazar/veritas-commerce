<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Actions\RecordWebhookEvent;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Data\ProviderEvent;
use App\Modules\Payments\Enums\ProviderEventStatus;
use App\Modules\Payments\Exceptions\ProviderSignatureInvalid;
use App\Modules\Payments\Jobs\ProcessProviderEvent;
use App\Modules\Payments\Models\ProviderWebhookEvent;
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
             * Already received — but "received" is not "handled", and the
             * difference cost a payment.
             *
             * The first version of this returned 200 here unconditionally.
             * That is correct when the earlier delivery got as far as
             * queueing the work, and catastrophic when it did not: if
             * Redis was away for the moment `dispatch()` ran, the event
             * row was committed, the queue push threw, and this endpoint
             * answered 500. The provider then redelivered — and landed
             * here, on a row that already existed, and was told 200. The
             * provider stopped retrying, no worker ever had the job, and
             * a customer who had genuinely paid was left in
             * pending_payment for ever.
             *
             * So a redelivery of an event that is still merely `received`
             * queues it again. Double-queueing is safe by construction:
             * ProcessProviderEvent claims its event with a conditional
             * UPDATE, so the second worker to arrive finds nothing to
             * claim and returns without touching a financial row.
             */
            $existing = $this->unfinished($event);

            if ($existing !== null) {
                ProcessProviderEvent::dispatch($existing->id);

                return response('Requeued.', 200);
            }

            /*
             * Genuinely handled already. 200, because from the provider's
             * point of view this delivery succeeded — and anything else
             * invites a retry loop over an event already dealt with.
             */
            return response('Already received.', 200);
        }

        ProcessProviderEvent::dispatch($stored->id);

        return response('Received.', 200);
    }

    /**
     * The stored event for this delivery, if nothing has handled it yet.
     *
     * `received` only. An event that is `processed` or `ignored` is
     * finished, and one that is `failed` is already inside the queue's
     * own retry schedule or has exhausted it and is waiting for a person
     * — requeueing either from here would be a second, uncoordinated
     * retry loop driven by whatever the provider happens to redeliver.
     */
    private function unfinished(ProviderEvent $event): ?ProviderWebhookEvent
    {
        return ProviderWebhookEvent::query()
            ->where('provider', $event->provider)
            ->where('event_id', $event->eventId)
            ->where('status', ProviderEventStatus::Received->value)
            ->first();
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
