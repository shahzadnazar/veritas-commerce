<?php

declare(strict_types=1);

namespace App\Modules\Events\Listeners;

use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentSucceeded;

/**
 * Payment outcomes, turned into the analytics stream.
 *
 * A purchase is recorded once per seller, not once per order. In a
 * marketplace the question "how much did this seller sell" is the whole
 * point of the table, and an event carrying only the customer's total
 * would answer it for nobody — the three sellers in one basket each made
 * a sale, of different sizes.
 *
 * Recorded here rather than in the payment action for the same reason cart
 * activity is: the payment module announces what happened and knows
 * nothing about analytics, which is what keeps it usable from a queued
 * job and a console command alike.
 *
 * The customer's identity comes from the event, not the request. A payment
 * is decided by a webhook in a queued job where there is no session, so
 * attribution would otherwise be lost precisely for the event that matters
 * most.
 */
final class RecordPaymentActivity
{
    public function __construct(private readonly RecordInteraction $interactions) {}

    public function succeeded(PaymentSucceeded $event): void
    {
        foreach ($event->lines as $line) {
            $this->interactions->record(
                request(),
                InteractionEventType::PurchaseCompleted,
                sellerAccountId: $line['sellerAccountId'],
                payload: [
                    'context' => 'payment',
                    'order_reference' => $event->orderReference,
                    'currency' => $event->currency,
                    'sellers' => count($event->lines),
                ],
                valueMinor: $line['valueMinor'],
                userId: $event->userId,
            );
        }
    }

    public function failed(PaymentFailed $event): void
    {
        $this->interactions->record(
            request(),
            InteractionEventType::PaymentFailed,
            payload: [
                'context' => 'payment',
                'order_reference' => $event->orderReference,
                'currency' => $event->currency,
                /*
                 * The provider's code, in the analytics stream only.
                 * Nothing renders this to a customer — a decline reason is
                 * a signal a card tester tunes the next attempt with, and
                 * §53 keeps the customer's wording separate.
                 */
                'failure_code' => $event->failureCode,
            ],
            valueMinor: $event->amountMinor,
            userId: $event->userId,
        );
    }
}
