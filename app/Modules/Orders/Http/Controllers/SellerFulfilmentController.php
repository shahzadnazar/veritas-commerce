<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Orders\Actions\AcknowledgeSellerOrder;
use App\Modules\Orders\Actions\CreateShipment;
use App\Modules\Orders\Actions\MarkShipmentDelivered;
use App\Modules\Orders\Actions\MarkShipmentShipped;
use App\Modules\Orders\Actions\ReportFulfilmentIssue;
use App\Modules\Orders\Actions\UpdateShipmentTracking;
use App\Modules\Orders\Data\ShipmentTracking;
use App\Modules\Orders\Enums\FulfilmentIssueReason;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The seller's fulfilment actions. Orchestration only.
 *
 * Every rule about whether a seller may confirm, pack, ship or record a
 * delivery lives in the domain actions this calls. What happens here is
 * the four things a controller is for: find the seller's own order,
 * validate the shape of the request, hand it to the action, and say what
 * happened.
 *
 * Isolation comes from SellerOrder's tenant scope plus a 404 rather than a
 * 403 — the same rule the rest of the portal follows. A seller guessing
 * another seller's order reference, or posting another seller's shipment
 * id, learns only that it does not exist for them. Every write below
 * re-resolves the shipment through its own seller order, so a shipment id
 * from outside the tenant never reaches an action at all.
 */
final class SellerFulfilmentController
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function confirm(Request $request, string $reference): RedirectResponse
    {
        $sellerOrder = $this->ownOrFail($reference);

        return $this->run(
            fn (): bool => app(AcknowledgeSellerOrder::class)->confirm(
                $sellerOrder,
                actorId: $this->actorId($request),
            ),
            $sellerOrder,
            'fulfilment.confirmed',
            'Order confirmed.',
        );
    }

    public function process(Request $request, string $reference): RedirectResponse
    {
        $sellerOrder = $this->ownOrFail($reference);

        return $this->run(
            fn (): bool => app(AcknowledgeSellerOrder::class)->startProcessing(
                $sellerOrder,
                actorId: $this->actorId($request),
            ),
            $sellerOrder,
            'fulfilment.processing',
            'Marked as being prepared.',
        );
    }

    /** Make up a parcel from the units this seller still owes. */
    public function createShipment(Request $request, string $reference): RedirectResponse
    {
        $sellerOrder = $this->ownOrFail($reference);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            // A carrier is not required to make up a box — it is required
            // to hand it over, which the domain enforces at that point.
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $shipment = app(CreateShipment::class)(
                $sellerOrder,
                $validated['lines'],
                ($validated['carrier'] ?? null) === null
                    ? null
                    : ShipmentTracking::of($validated['carrier'], $validated['tracking_number'] ?? null),
                actorId: $this->actorId($request),
                notes: $validated['notes'] ?? null,
            );
        } catch (FulfilmentRefused $refused) {
            return back()->withErrors(['fulfilment' => $refused->getMessage()]);
        }

        $this->record('fulfilment.shipment_created', $sellerOrder, $request, [
            'shipment_reference' => $shipment->reference,
            'units' => $shipment->unitCount(),
        ]);

        return back()->with('status', "Shipment {$shipment->reference} created.");
    }

    public function updateTracking(Request $request, string $reference, string $shipment): RedirectResponse
    {
        $sellerOrder = $this->ownOrFail($reference);
        $parcel = $this->parcelOrFail($sellerOrder, $shipment);

        $validated = $request->validate([
            'carrier' => ['required', 'string', 'max:120'],
            'tracking_number' => ['required', 'string', 'max:100'],
        ]);

        try {
            $changed = app(UpdateShipmentTracking::class)(
                $parcel,
                ShipmentTracking::of($validated['carrier'], $validated['tracking_number']),
                actorId: $this->actorId($request),
            );
        } catch (FulfilmentRefused $refused) {
            return back()->withErrors(['fulfilment' => $refused->getMessage()]);
        }

        if ($changed) {
            $this->record('fulfilment.tracking_updated', $sellerOrder, $request, [
                'shipment_reference' => $parcel->reference,
                'carrier' => $parcel->carrier_name,
            ]);
        }

        return back()->with('status', 'Tracking updated.');
    }

    public function ship(Request $request, string $reference, string $shipment): RedirectResponse
    {
        $sellerOrder = $this->ownOrFail($reference);
        $parcel = $this->parcelOrFail($sellerOrder, $shipment);

        try {
            $sent = app(MarkShipmentShipped::class)($parcel, actorId: $this->actorId($request));
        } catch (FulfilmentRefused $refused) {
            return back()->withErrors(['fulfilment' => $refused->getMessage()]);
        }

        if ($sent) {
            $this->record('fulfilment.shipped', $sellerOrder, $request, [
                'shipment_reference' => $parcel->reference,
            ]);
        }

        return back()->with('status', "Shipment {$parcel->reference} marked as sent.");
    }

    /**
     * Record that a parcel arrived.
     *
     * §19 and §56: in Phase 1 a person says so, because there is no
     * carrier integration. The screen says as much rather than implying
     * the platform watched it happen.
     */
    public function deliver(Request $request, string $reference, string $shipment): RedirectResponse
    {
        $sellerOrder = $this->ownOrFail($reference);
        $parcel = $this->parcelOrFail($sellerOrder, $shipment);

        try {
            $arrived = app(MarkShipmentDelivered::class)($parcel, actorId: $this->actorId($request));
        } catch (FulfilmentRefused $refused) {
            return back()->withErrors(['fulfilment' => $refused->getMessage()]);
        }

        if ($arrived) {
            $this->record('fulfilment.delivered', $sellerOrder, $request, [
                'shipment_reference' => $parcel->reference,
            ]);
        }

        return back()->with('status', "Shipment {$parcel->reference} marked as delivered.");
    }

    /**
     * Raise a hand, rather than reach for the platform's money.
     *
     * §26: a seller who cannot fulfil reports it and an admin holding the
     * refund permission decides. Giving the seller the refund button would
     * mean the party with the incentive to make a problem disappear is
     * also the party who can pay for it.
     */
    public function reportIssue(Request $request, string $reference): RedirectResponse
    {
        $sellerOrder = $this->ownOrFail($reference);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:64'],
            'note' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        $reason = FulfilmentIssueReason::tryFrom($validated['reason']);

        if ($reason === null) {
            return back()->withErrors(['reason' => 'Choose one of the listed problems.']);
        }

        app(ReportFulfilmentIssue::class)(
            $sellerOrder,
            $reason,
            $validated['note'],
            reportedById: $this->actorId($request),
        );

        $this->record('fulfilment.issue_reported', $sellerOrder, $request, ['reason' => $reason->value]);

        return back()->with('status', 'Reported. The marketplace team will pick this up.');
    }

    /**
     * @param  callable(): bool  $transition
     */
    private function run(
        callable $transition,
        SellerOrder $sellerOrder,
        string $auditAction,
        string $message,
    ): RedirectResponse {
        try {
            $moved = $transition();
        } catch (FulfilmentRefused $refused) {
            return back()->withErrors(['fulfilment' => $refused->getMessage()]);
        }

        if ($moved) {
            $this->record($auditAction, $sellerOrder, request(), []);
        }

        return back()->with('status', $message);
    }

    private function ownOrFail(string $reference): SellerOrder
    {
        /** @var SellerOrder|null $sellerOrder */
        $sellerOrder = SellerOrder::query()->where('reference', $reference)->first();

        if ($sellerOrder === null) {
            // The tenant scope already removed another seller's rows; the
            // 404 keeps their existence private too.
            throw new NotFoundHttpException;
        }

        return $sellerOrder;
    }

    /**
     * A parcel of THIS seller order.
     *
     * Resolved by public id within the order rather than by id alone, so a
     * shipment identifier lifted from another seller's page matches
     * nothing here however well-formed it is.
     */
    private function parcelOrFail(SellerOrder $sellerOrder, string $publicId): Shipment
    {
        /** @var Shipment|null $shipment */
        $shipment = Shipment::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->where('public_id', $publicId)
            ->first();

        if ($shipment === null) {
            throw new NotFoundHttpException;
        }

        return $shipment;
    }

    private function actorId(Request $request): ?int
    {
        $user = $request->user('web');

        return $user === null ? null : (int) $user->getAuthIdentifier();
    }

    /** @param array<string, mixed> $changes */
    private function record(string $action, SellerOrder $sellerOrder, Request $request, array $changes): void
    {
        ($this->audit)(
            action: $action,
            actorType: 'seller',
            actorId: $this->actorId($request),
            subjectType: SellerOrder::class,
            subjectId: $sellerOrder->id,
            changes: array_merge(['seller_order' => $sellerOrder->reference], $changes),
        );
    }
}
