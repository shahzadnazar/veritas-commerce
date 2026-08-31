<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Data\CaptureResult;
use App\Modules\Payments\Data\WebhookEvent;

/**
 * The payment port.
 *
 * The domain depends on this interface, never on a vendor SDK, so Stripe,
 * PayPal Commerce or Adyen are a binding change rather than a rewrite.
 * M0 ships the fake driver; the Stripe driver arrives in M3.
 */
interface PaymentGateway
{
    public function capture(string $orderReference, int $amountMinor, string $currency, string $idempotencyKey): CaptureResult;

    public function refund(string $chargeId, int $amountMinor, string $idempotencyKey): CaptureResult;

    /** Verifies the signature and returns the event, or throws. */
    public function parseWebhook(string $payload, string $signature): WebhookEvent;
}
