<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

/**
 * What a piece of seller paperwork is.
 *
 * A closed set rather than free text, because a reviewer needs to know at
 * a glance whether the thing in front of them is the registration
 * certificate they asked for or a second copy of a utility bill.
 */
enum DocumentKind: string
{
    case BusinessRegistration = 'business_registration';
    case TaxRegistration = 'tax_registration';
    case IdentityDocument = 'identity_document';
    case BankStatement = 'bank_statement';
    case ProofOfAddress = 'proof_of_address';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BusinessRegistration => 'Business registration certificate',
            self::TaxRegistration => 'Tax registration document',
            self::IdentityDocument => 'Identity document',
            self::BankStatement => 'Bank statement',
            self::ProofOfAddress => 'Proof of address',
            self::Other => 'Other supporting document',
        };
    }

    /**
     * Which kinds a reviewer expects before deciding.
     *
     * Configuration, not a constant: what a marketplace must collect
     * depends on where it operates, and that is not a code change.
     *
     * @return array<int, self>
     */
    public static function required(): array
    {
        $configured = (array) config('veritas.sellers.required_documents');

        return array_values(array_filter(array_map(
            static fn (mixed $value): ?self => is_string($value) ? self::tryFrom($value) : null,
            $configured,
        )));
    }

    /** @return array<int, array{value: string, label: string, required: bool}> */
    public static function options(): array
    {
        $required = array_map(static fn (self $kind): string => $kind->value, self::required());

        return array_map(
            static fn (self $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'required' => in_array($kind->value, $required, true),
            ],
            self::cases(),
        );
    }
}
