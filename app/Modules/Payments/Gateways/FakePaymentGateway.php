<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\CaptureResult;
use App\Modules\Payments\Data\WebhookEvent;
use RuntimeException;

/**
 * The M0 driver: no network, deterministic, and honest about being fake.
 *
 * It exists so the checkout path and the webhook path can be built and
 * tested end to end before the Stripe driver lands in M3.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** Amounts ending in these cents fail, so failure paths are testable. */
    public const DECLINE_CENTS = 2;

    public function capture(string $orderReference, int $amountMinor, string $currency, string $idempotencyKey): CaptureResult
    {
        if ($amountMinor % 100 === self::DECLINE_CENTS) {
            return new CaptureResult(
                succeeded: false,
                failureCode: 'card_declined',
                failureMessage: 'Your card was declined by the issuer.',
                raw: ['idempotency_key' => $idempotencyKey],
            );
        }

        return new CaptureResult(
            succeeded: true,
            chargeId: 'fake_ch_'.hash('xxh128', $orderReference.$idempotencyKey),
            raw: ['amount_minor' => $amountMinor, 'currency' => $currency],
        );
    }

    public function refund(string $chargeId, int $amountMinor, string $idempotencyKey): CaptureResult
    {
        return new CaptureResult(
            succeeded: true,
            chargeId: 'fake_re_'.hash('xxh128', $chargeId.$idempotencyKey),
            raw: ['amount_minor' => $amountMinor],
        );
    }

    public function parseWebhook(string $payload, string $signature): WebhookEvent
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! isset($decoded['id'], $decoded['type'])) {
            throw new RuntimeException('Malformed webhook payload.');
        }

        if ($signature !== $this->sign($payload)) {
            throw new RuntimeException('Webhook signature verification failed.');
        }

        return new WebhookEvent(
            provider: 'fake',
            eventId: (string) $decoded['id'],
            type: (string) $decoded['type'],
            payload: $decoded,
        );
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
