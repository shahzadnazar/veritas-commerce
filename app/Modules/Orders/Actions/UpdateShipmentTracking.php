<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Data\ShipmentTracking;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a parcel's carrier or tracking number.
 *
 * §15's policy, and the shape of it is about who was told what. Before the
 * parcel goes, tracking is a working detail and the seller may change it
 * freely. Once it has gone the customer has been given a number they are
 * checking, so a correction is still allowed but recorded — the previous
 * value stays in the history, and nobody can quietly replace what the
 * customer was originally told.
 *
 * After delivery it stops being a working detail altogether: the tracking
 * is the evidence that the parcel arrived, and rewriting it is exactly
 * what somebody would do to a disputed delivery. That needs a platform
 * administrator with the correction permission and a written reason.
 *
 * The URL is never taken from the caller. For a carrier the platform knows
 * it is generated; for one it does not, there is no link. A seller-supplied
 * URL is an instruction to a customer's browser that the marketplace would
 * be vouching for.
 */
final class UpdateShipmentTracking
{
    public function __invoke(
        Shipment $shipment,
        ShipmentTracking $tracking,
        string $actorType = 'seller',
        ?int $actorId = null,
        ?string $reason = null,
        bool $mayCorrectHistory = false,
    ): bool {
        return DB::transaction(function () use ($shipment, $tracking, $actorType, $actorId, $reason, $mayCorrectHistory): bool {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === ShipmentStatus::Cancelled) {
                throw FulfilmentRefused::shipmentAlreadyGone();
            }

            /*
             * A delivered parcel's tracking is a historical record. Only
             * an administrator holding the correction permission may touch
             * it, and only with a reason that goes into the history beside
             * the value it replaced.
             */
            if ($locked->status === ShipmentStatus::Delivered) {
                if (! $mayCorrectHistory) {
                    throw FulfilmentRefused::trackingIsHistory();
                }

                if ($reason === null || trim($reason) === '') {
                    throw FulfilmentRefused::reasonRequired();
                }
            }

            $unchanged = $locked->carrier_name === $tracking->carrierName
                && $locked->tracking_number === $tracking->trackingNumber;

            if ($unchanged) {
                return false;
            }

            /*
             * Written BEFORE the change, carrying the values as they were.
             * A history row that recorded the new number would say what
             * anyone can already read and lose the only thing that matters
             * in a dispute — what the customer was told before.
             */
            ShipmentStatusHistory::query()->create([
                'shipment_id' => $locked->id,
                'from_status' => $locked->status->value,
                'to_status' => $locked->status->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason ?? 'Tracking updated.',
                'carrier_name' => $locked->carrier_name,
                'tracking_number' => $locked->tracking_number,
                'created_at' => now(),
            ]);

            $locked->forceFill([
                'carrier_name' => $tracking->carrierName,
                'carrier_code' => $tracking->carrierCode,
                'tracking_number' => $tracking->trackingNumber,
                'tracking_url' => $tracking->url(),
            ])->save();

            $shipment->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
