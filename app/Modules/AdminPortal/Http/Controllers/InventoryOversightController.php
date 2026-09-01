<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Actions\ResolveInventoryBalance;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\StockState;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Queries\MovementHistory;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Queries\InventoryRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operational visibility over every seller's stock.
 *
 * Not warehouse management: the platform reads stock to answer "why can
 * nobody buy this", and corrects it only when a seller cannot. So viewing
 * and adjusting are separate permissions, and an adjustment made here is
 * marked as the platform's — a seller looking at their own history must be
 * able to see that the marketplace changed their count, and why.
 */
final class InventoryOversightController
{
    public function __construct(
        private readonly InventoryRows $rows,
        private readonly MovementHistory $history,
        private readonly AdjustInventory $adjust,
        private readonly ResolveInventoryBalance $resolveBalance,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize($request, AdminPermission::InventoryView);

        $offers = ($this->rows)($request);

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
            'can' => ['adjust' => $this->admin($request)->role->can(AdminPermission::InventoryAdjust)],
        ]);
    }

    public function show(Request $request, string $publicId): Response
    {
        $this->authorize($request, AdminPermission::InventoryView);

        $offer = $this->find($publicId);
        $balance = ($this->resolveBalance)($offer);

        return Inertia::render('Inventory/Detail', [
            'offer' => [
                'publicId' => $offer->public_id,
                'sku' => $offer->seller_sku,
                'productTitle' => $offer->product->title ?? 'Unknown product',
                'variantName' => $offer->productVariant->name ?? null,
                'status' => $offer->status->value,
                'sellerName' => $offer->sellerAccount->legal_name ?? null,
                'storeName' => $offer->store->name ?? null,
                'effectiveThreshold' => $balance->lowStockThreshold(),
            ],
            'level' => $balance->level()->toArray(),
            'movements' => ($this->history)($offer->id),
            'can' => ['adjust' => $this->admin($request)->role->can(AdminPermission::InventoryAdjust)],
        ]);
    }

    public function adjust(Request $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::InventoryAdjust);

        $validated = $request->validate([
            'change' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            // Required, always. The platform reaching into a seller's
            // stock without saying why is not something the record should
            // be able to contain.
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $offer = $this->find($publicId);
        $admin = $this->admin($request);

        try {
            ($this->adjust)(
                $offer,
                (int) $validated['change'],
                InventoryMovementReason::AdminAdjustment,
                'admin',
                $admin->id,
                (string) $validated['reason'],
            );
        } catch (InvalidStockOperation $exception) {
            throw ValidationException::withMessages(['change' => $exception->getMessage()]);
        }

        return back()->with('success', 'Stock adjusted and recorded against your account.');
    }

    private function find(string $publicId): Offer
    {
        return Offer::query()
            ->with(['product', 'productVariant', 'store', 'sellerAccount'])
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->user('admin');

        abort_if(! $admin instanceof AdminUser, 403);

        return $admin;
    }

    private function authorize(Request $request, AdminPermission $permission): void
    {
        abort_unless($this->admin($request)->role->can($permission), 403);
    }
}
