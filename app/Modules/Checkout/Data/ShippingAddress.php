<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use InvalidArgumentException;

/**
 * Where the order is going, frozen at the attempt.
 *
 * Taken as a snapshot rather than a foreign key to a saved address: a
 * customer editing their address book must not silently rewrite where a
 * placed order was sent, and an address deleted afterwards must not leave
 * an order pointing at nothing.
 *
 * `state` is optional. §33 — Singapore, Malta and the Vatican have no
 * state, and requiring one is a US-shaped assumption dressed up as
 * validation.
 */
final readonly class ShippingAddress
{
    public function __construct(
        public string $name,
        public string $line1,
        public ?string $line2,
        public string $city,
        public ?string $state,
        public string $postcode,
        public string $country,
        public ?string $phone = null,
    ) {
        foreach (['name' => $name, 'line1' => $line1, 'city' => $city, 'postcode' => $postcode] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("A shipping address needs a {$field}.");
            }
        }

        if (strlen($country) !== 2) {
            throw new InvalidArgumentException('Country must be an ISO 3166-1 alpha-2 code.');
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            name: self::string($row, 'name'),
            line1: self::string($row, 'line1'),
            line2: self::nullableString($row, 'line2'),
            city: self::string($row, 'city'),
            state: self::nullableString($row, 'state'),
            postcode: self::string($row, 'postcode'),
            country: strtoupper(self::string($row, 'country')),
            phone: self::nullableString($row, 'phone'),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'phone' => $this->phone,
        ];
    }

    /**
     * The order's own ship_* columns.
     *
     * @return array<string, string|null>
     */
    public function toOrderColumns(): array
    {
        return [
            'ship_name' => $this->name,
            'ship_line1' => $this->line1,
            'ship_line2' => $this->line2,
            'ship_city' => $this->city,
            'ship_state' => $this->state,
            'ship_postcode' => $this->postcode,
            'ship_country' => $this->country,
            'ship_phone' => $this->phone,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        $value = self::string($row, $key);

        return $value === '' ? null : $value;
    }
}
