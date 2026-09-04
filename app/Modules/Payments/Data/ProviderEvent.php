<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

/**
 * One inbound event from a provider, after its signature has been verified.
 *
 * Constructing this is the act of saying "this really came from the
 * provider". The adapter verifies the signature against the shared secret
 * and refuses to build one otherwise, so a handler holding a ProviderEvent
 * does not have to remember to check — it could not have got one.
 *
 * `objectReference` is the provider's id for the thing the event is about
 * (a payment, a refund). It is a lookup key, not an authorisation: the
 * processing path still reads the provider's own copy of that object
 * before believing anything about it.
 */
final readonly class ProviderEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $type,
        public ?string $objectReference,
        public array $payload,
        /**
         * The provider's own creation time, in epoch seconds.
         *
         * §14: events arrive out of order, and this is how two events about
         * one payment are put back in sequence.
         */
        public ?int $occurredAt = null,
    ) {}
}
