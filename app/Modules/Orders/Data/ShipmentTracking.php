<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Support\Carriers;

/**
 * A carrier and a tracking number, checked before they reach the database.
 *
 * The URL is the part worth caring about. A tracking link is rendered to a
 * customer and clicked, so a seller-supplied one is a link the marketplace
 * is vouching for. For a carrier the platform knows, the URL is generated
 * from the tracking number and the seller cannot supply one at all; for
 * anything else it is dropped rather than trusted. Sellers can still type
 * any carrier name they like — a marketplace that only accepted five
 * couriers would be wrong about the world — but a name is text, and a link
 * is an instruction to a browser.
 */
final readonly class ShipmentTracking
{
    private function __construct(
        public string $carrierName,
        public ?string $carrierCode,
        public ?string $trackingNumber,
    ) {}

    /**
     * @param  string  $carrier  a code the platform knows, or a free-text name
     */
    public static function of(string $carrier, ?string $trackingNumber = null): self
    {
        $carrier = trim($carrier);
        $trackingNumber = $trackingNumber === null ? null : trim($trackingNumber);

        $code = Carriers::codeFor($carrier);

        return new self(
            carrierName: $code === null ? mb_substr($carrier, 0, 120) : Carriers::nameFor($code),
            carrierCode: $code,
            trackingNumber: $trackingNumber === '' ? null : mb_substr((string) $trackingNumber, 0, 100),
        );
    }

    /** Whether this is enough to tell a customer their parcel is on its way. */
    public function isComplete(): bool
    {
        return $this->carrierName !== '' && $this->trackingNumber !== null;
    }

    /**
     * The link, generated and never accepted.
     *
     * Null for an unknown carrier: no link at all is better than one the
     * marketplace cannot vouch for.
     */
    public function url(): ?string
    {
        if ($this->carrierCode === null || $this->trackingNumber === null) {
            return null;
        }

        return Carriers::trackingUrl($this->carrierCode, $this->trackingNumber);
    }
}
