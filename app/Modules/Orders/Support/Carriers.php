<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * The couriers the platform can build a tracking link for.
 *
 * Deliberately a short list and deliberately not an integration. There is
 * no API call here, no rate lookup and no label: Phase 1 records what a
 * seller typed. What this adds is the one thing worth adding — a URL the
 * marketplace generates itself.
 *
 * That matters because a tracking link is rendered to a customer and
 * clicked. A seller-supplied URL is an instruction to a browser that the
 * platform would be vouching for, and "it was in the tracking field" is
 * not a defence. So known carriers get a generated link and unknown ones
 * get none, while any carrier name at all is still accepted — a
 * marketplace that only shipped by five couriers would be wrong about the
 * world.
 *
 * A future ShippingProvider can take this over without the shipment domain
 * changing: it already only asks for a code, a name and a URL.
 */
final class Carriers
{
    /** @var array<string, array{name: string, url: string}> */
    private const KNOWN = [
        'usps' => [
            'name' => 'USPS',
            'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
        ],
        'ups' => [
            'name' => 'UPS',
            'url' => 'https://www.ups.com/track?tracknum=%s',
        ],
        'fedex' => [
            'name' => 'FedEx',
            'url' => 'https://www.fedex.com/fedextrack/?trknbr=%s',
        ],
        'dhl' => [
            'name' => 'DHL',
            'url' => 'https://www.dhl.com/en/express/tracking.html?AWB=%s',
        ],
        'royal_mail' => [
            'name' => 'Royal Mail',
            'url' => 'https://www.royalmail.com/track-your-item#/tracking-results/%s',
        ],
        'evri' => [
            'name' => 'Evri',
            'url' => 'https://www.evri.com/track/parcel/%s',
        ],
    ];

    /**
     * Every carrier the platform knows, for a picker.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::KNOWN as $code => $carrier) {
            $out[] = ['code' => $code, 'name' => $carrier['name']];
        }

        return $out;
    }

    /** The code for a carrier code or display name, or null if unknown. */
    public static function codeFor(string $carrier): ?string
    {
        $needle = strtolower(str_replace([' ', '-'], '_', trim($carrier)));

        if (isset(self::KNOWN[$needle])) {
            return $needle;
        }

        foreach (self::KNOWN as $code => $known) {
            if (strtolower($known['name']) === strtolower(trim($carrier))) {
                return $code;
            }
        }

        return null;
    }

    public static function nameFor(string $code): string
    {
        return self::KNOWN[$code]['name'] ?? $code;
    }

    /**
     * The tracking URL, built from a template rather than from input.
     *
     * The tracking number is URL-encoded on the way in, so a value that
     * tried to be a query string of its own becomes one path segment.
     */
    public static function trackingUrl(string $code, string $trackingNumber): ?string
    {
        $carrier = self::KNOWN[$code] ?? null;

        if ($carrier === null) {
            return null;
        }

        return sprintf($carrier['url'], rawurlencode($trackingNumber));
    }
}
