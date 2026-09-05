<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\FulfilmentIssueReason;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\FulfilmentIssue;
use App\Modules\Orders\Models\SellerOrder;

/**
 * A seller says an order cannot be fulfilled as sold.
 *
 * §26 and §27, and what this action deliberately is NOT is a refund
 * button. A seller who cannot ship needs a way to raise their hand; giving
 * them the platform's money to resolve it themselves is a different
 * authority, and one that already lives behind `orders.refund` with an
 * admin holding it. Weakening that to save a click would mean the party
 * with the incentive to make a problem disappear is also the party who can
 * pay for it.
 *
 * So the flow is: the seller reports, an admin with the refund permission
 * decides. The report is structured enough to be filtered on — "how often
 * does stock turn out not to exist after a sale" is a question about the
 * marketplace — with a written note for the detail.
 */
final class ReportFulfilmentIssue
{
    public function __invoke(
        SellerOrder $sellerOrder,
        FulfilmentIssueReason $reason,
        string $note,
        string $reportedByType = 'seller',
        ?int $reportedById = null,
        ?int $shipmentId = null,
    ): FulfilmentIssue {
        if (trim($note) === '') {
            throw FulfilmentRefused::reasonRequired();
        }

        /** @var FulfilmentIssue $issue */
        $issue = FulfilmentIssue::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'shipment_id' => $shipmentId,
            'reason' => $reason->value,
            'note' => mb_substr(trim($note), 0, 2_000),
            'reported_by_type' => $reportedByType,
            'reported_by_id' => $reportedById,
        ]);

        return $issue;
    }
}
