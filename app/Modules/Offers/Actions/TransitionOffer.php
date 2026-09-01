<?php

declare(strict_types=1);

namespace App\Modules\Offers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Events\OfferActivated;
use App\Modules\Offers\Events\OfferSuspended;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Moves an offer through its own lifecycle.
 *
 * Deliberately separate from the product's: a published product may carry
 * a suspended offer, and an offer a seller has paused is a different thing
 * from a product the platform has pulled. Conflating them makes "why can
 * nobody buy this" unanswerable.
 */
final class TransitionOffer
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(
        Offer $offer,
        OfferStatus $to,
        string $actorType,
        ?int $actorId = null,
        ?string $reason = null,
    ): Offer {
        $from = $offer->status;

        if ($from === $to) {
            return $offer;
        }

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw new RuntimeException("An offer cannot move from {$from->value} to {$to->value}.");
        }

        if ($to->requiresReason() && trim((string) $reason) === '') {
            throw new RuntimeException("Moving an offer to {$to->value} requires a written reason.");
        }

        if ($to === OfferStatus::Published) {
            $this->assertPublishable($offer);
        }

        return DB::transaction(function () use ($offer, $from, $to, $actorType, $actorId, $reason): Offer {
            $offer->status = $to;
            $offer->moderation_reason = $to->requiresReason() ? $reason : null;

            if ($to === OfferStatus::Published && $offer->published_at === null) {
                $offer->published_at = Carbon::now();
            }

            if ($to === OfferStatus::Archived) {
                $offer->archived_at = Carbon::now();
            }

            $offer->save();

            ($this->audit)(
                action: 'catalogue.offer.'.$to->value,
                actorType: $actorType,
                actorId: $actorId,
                subjectType: Offer::class,
                subjectId: $offer->id,
                changes: ['status' => ['from' => $from->value, 'to' => $to->value]],
                reason: $reason,
            );

            $offerId = $offer->id;

            DB::afterCommit(function () use ($offerId, $to, $actorId, $reason): void {
                $event = match ($to) {
                    OfferStatus::Published => new OfferActivated($offerId, $actorId),
                    OfferStatus::Suspended => new OfferSuspended($offerId, $actorId, $reason),
                    default => null,
                };

                if ($event !== null) {
                    Event::dispatch($event);
                }
            });

            return $offer;
        });
    }

    /**
     * A seller cannot put an offer live if they or their product are not.
     *
     * Checked at the moment of publishing rather than only when reading,
     * so a suspended seller cannot leave a live offer behind to be found
     * later by a surface that forgot to filter.
     */
    private function assertPublishable(Offer $offer): void
    {
        $seller = $offer->sellerAccount;

        if ($seller === null || $seller->status !== SellerStatus::Approved) {
            throw new RuntimeException('A suspended seller cannot put a listing live.');
        }

        $product = $offer->product;

        if ($product === null || ! $product->status->acceptsOffers()) {
            throw new RuntimeException(
                'That product is not accepting offers, so this listing cannot go live.'
            );
        }
    }
}
