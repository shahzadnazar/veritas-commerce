<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Data;

use App\Support\Money;

/**
 * One line of a seller's financial statement.
 *
 * Credit and debit are separate columns rather than one signed number,
 * because that is how a statement is read: a person scanning for "what
 * came out" should not have to notice a minus sign. The signed amount
 * travels too, for anything doing arithmetic.
 */
final readonly class SellerLedgerRow
{
    public function __construct(
        public string $publicId,
        public string $occurredAt,
        public string $type,
        public string $typeLabel,
        public string $status,
        public string $statusLabel,
        public string $description,
        public int $amountMinor,
        public int $balanceAfterMinor,
        public string $currency,
        public ?string $availableAt = null,
        /** The seller order or payout this line belongs to, if any. */
        public ?string $reference = null,
        public ?string $referenceKind = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->publicId,
            'occurredAt' => $this->occurredAt,
            'type' => $this->type,
            'typeLabel' => $this->typeLabel,
            'status' => $this->status,
            'statusLabel' => $this->statusLabel,
            'description' => $this->description,
            'amountMinor' => $this->amountMinor,
            'creditMinor' => $this->amountMinor > 0 ? $this->amountMinor : 0,
            'debitMinor' => $this->amountMinor < 0 ? -$this->amountMinor : 0,
            'credit' => $this->amountMinor > 0 ? Money::of($this->amountMinor, $this->currency)->format() : null,
            'debit' => $this->amountMinor < 0 ? Money::of(-$this->amountMinor, $this->currency)->format() : null,
            'balanceAfterMinor' => $this->balanceAfterMinor,
            'balanceAfter' => Money::formatSigned($this->balanceAfterMinor, $this->currency),
            'currency' => $this->currency,
            'availableAt' => $this->availableAt,
            'reference' => $this->reference,
            'referenceKind' => $this->referenceKind,
        ];
    }
}
