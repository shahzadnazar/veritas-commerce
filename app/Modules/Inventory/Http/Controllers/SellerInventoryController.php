<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Actions\ResolveInventoryBalance;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\StockState;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Http\Requests\AdjustStockRequest;
use App\Modules\Inventory\Queries\MovementHistory;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Queries\InventoryRows;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A seller's own stock.
 *
 * Every lookup is scoped to the acting seller in the query itself, so an
 * offer id belonging to another store does not resolve — there is no id in
 * any of these requests that decides whose stock is being read or changed.
 */
final class SellerInventoryController
{
    public function __construct(
        private readonly InventoryRows $rows,
        private readonly MovementHistory $history,
        private readonly AdjustInventory $adjust,
        private readonly ResolveInventoryBalance $resolveBalance,
    ) {}

    public function index(Request $request): Response
    {
        $offers = ($this->rows)($request, $this->sellerId());

        return Inertia::render('Inventory/Index', [
            'rows' => [
                'data' => array_map(InventoryRows::present(...), $offers->items()),
                'currentPage' => $offers->currentPage(),
                'lastPage' => $offers->lastPage(),
                'total' => $offers->total(),
            ],
            'filters' => [
                'search' => $request->string('search')->toString(),
                'state' => $request->string('state')->toString(),
            ],
            'states' => array_map(
                static fn (StockState $state): array => ['value' => $state->value, 'label' => $state->label()],
                StockState::cases(),
            ),
            'can' => ['manage' => CurrentSeller::can(SellerPermission::InventoryManage)],
        ]);
    }

    public function show(string $publicId): Response
    {
        $offer = $this->ownOffer($publicId);
        $balance = ($this->resolveBalance)($offer);

        return Inertia::render('Inventory/Detail', [
            'offer' => [
                'publicId' => $offer->public_id,
                'sku' => $offer->seller_sku,
                'productTitle' => $offer->product->title ?? 'Unknown product',
                'variantName' => $offer->productVariant->name ?? null,
                'status' => $offer->status->value,
                'lowStockThreshold' => $offer->low_stock_threshold,
                'effectiveThreshold' => $balance->lowStockThreshold(),
            ],
            'level' => $balance->level()->toArray(),
            'movements' => ($this->history)($offer->id),
            'reasons' => $this->sellerReasons(),
            'can' => ['manage' => CurrentSeller::can(SellerPermission::InventoryManage)],
        ]);
    }

    public function adjust(AdjustStockRequest $request, string $publicId): RedirectResponse
    {
        abort_unless(CurrentSeller::can(SellerPermission::InventoryManage), 403);

        $offer = $this->ownOffer($publicId);
        $user = $request->user('web');
        abort_if($user === null, 403);

        try {
            ($this->adjust)(
                $offer,
                $request->change(),
                $request->movementReason(),
                'seller',
                (int) $user->getAuthIdentifier(),
                $request->note(),
            );
        } catch (InvalidStockOperation $exception) {
            // The domain's message is written to be read by the person who
            // attempted it, so it becomes the field error rather than a 500.
            throw ValidationException::withMessages(['change' => $exception->getMessage()]);
        }

        return back()->with('success', 'Stock updated.');
    }

    public function openingStock(Request $request, string $publicId): RedirectResponse
    {
        abort_unless(CurrentSeller::can(SellerPermission::InventoryManage), 403);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $offer = $this->ownOffer($publicId);
        $user = $request->user('web');
        abort_if($user === null, 403);

        try {
            $this->adjust->openingStock($offer, (int) $validated['quantity'], 'seller', (int) $user->getAuthIdentifier());
        } catch (InvalidStockOperation $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        return back()->with('success', 'Opening stock recorded.');
    }

    public function threshold(Request $request, string $publicId): RedirectResponse
    {
        abort_unless(CurrentSeller::can(SellerPermission::InventoryManage), 403);

        $validated = $request->validate([
            // Nullable is "inherit the store's"; zero is "never warn me".
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $offer = $this->ownOffer($publicId);
        $offer->forceFill(['low_stock_threshold' => $validated['low_stock_threshold'] ?? null])->save();

        return back()->with('success', 'Low-stock threshold saved.');
    }

    /**
     * An offer belonging to the acting seller.
     *
     * Scoped in the query rather than checked after loading: a crafted
     * public id reaches a 404 instead of another seller's stock.
     */
    private function ownOffer(string $publicId): Offer
    {
        return Offer::query()
            ->with(['product', 'productVariant', 'store'])
            ->where('seller_account_id', $this->sellerId())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /** @return array<int, array<string, string>> */
    private function sellerReasons(): array
    {
        return array_values(array_map(
            static fn (InventoryMovementReason $reason): array => [
                'value' => $reason->value,
                'label' => $reason->label(),
                'requiresNote' => $reason->requiresNote() ? 'yes' : 'no',
            ],
            array_filter(
                InventoryMovementReason::cases(),
                static fn (InventoryMovementReason $reason): bool => $reason->isSellerSelectable(),
            ),
        ));
    }

    private function sellerId(): int
    {
        $sellerId = CurrentSeller::id();
        abort_if($sellerId === null, 404);

        return $sellerId;
    }
}
