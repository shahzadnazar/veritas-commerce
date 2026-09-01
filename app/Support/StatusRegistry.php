<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockState;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Sellers\Enums\InvitationStatus;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Enums\SellerStatus;
use BackedEnum;

/**
 * The single list of status enums the UI renders.
 *
 * Phase 6 consistency review, finding 1: the status-to-tone mapping was
 * duplicated three times in the prototype, once per area. Here there is one
 * source. `php artisan statuses:export` writes the TypeScript from this
 * registry, and StatusPresentationTest fails if the two ever diverge — so a
 * status added after handoff cannot reach the UI unmapped.
 *
 * @phpstan-type StatusEnum class-string<HasStatusTone&\BackedEnum>
 */
final class StatusRegistry
{
    /**
     * Domain key => enum class. The key is the namespace a frontend badge
     * addresses, e.g. statusTone('seller_order', 'shipped').
     *
     * @return array<string, class-string>
     */
    public static function map(): array
    {
        return [
            'seller_application' => SellerApplicationStatus::class,
            'seller' => SellerStatus::class,
            'seller_invitation' => InvitationStatus::class,
            'product' => ProductStatus::class,
            'offer' => OfferStatus::class,
            'marketplace_order' => MarketplaceOrderStatus::class,
            'seller_order' => SellerOrderStatus::class,
            'payment' => PaymentStatus::class,
            'payout' => PayoutStatus::class,
            'ledger_entry_status' => LedgerEntryStatus::class,
            'ledger_entry_type' => LedgerEntryType::class,
            'inventory_movement_reason' => InventoryMovementReason::class,
            'inventory_reservation' => ReservationStatus::class,
            'stock' => StockState::class,
        ];
    }

    /**
     * Every case of every registered enum, flattened for export and testing.
     *
     * @return array<string, array<string, array{tone: string, label: string}>>
     */
    public static function presentation(): array
    {
        $out = [];

        foreach (self::map() as $domain => $enum) {
            /** @var array<int, HasStatusTone&BackedEnum> $cases */
            $cases = $enum::cases();

            foreach ($cases as $case) {
                $out[$domain][(string) $case->value] = [
                    'tone' => $case->tone()->value,
                    'label' => $case->label(),
                ];
            }
        }

        return $out;
    }

    /**
     * Enums that are workflows rather than labels.
     *
     * @return array<string, class-string>
     */
    public static function stateMachines(): array
    {
        return array_filter(
            self::map(),
            static fn (string $enum): bool => in_array(StatusTransitions::class, class_implements($enum) ?: [], true),
        );
    }
}
