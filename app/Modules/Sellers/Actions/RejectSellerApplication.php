<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Models\SellerApplication;
use Illuminate\Support\Facades\DB;

/**
 * Rejects an application, with a reason that is shown to the applicant
 * verbatim and kept on the record permanently.
 *
 * The reason is required by the transition guard, not by the form, so a
 * request that skips the UI cannot reject without one.
 */
final class RejectSellerApplication
{
    public function __construct(
        private readonly TransitionSellerApplication $transition,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function __invoke(SellerApplication $application, int $decidedByAdminId, string $reason): SellerApplication
    {
        return DB::transaction(function () use ($application, $decidedByAdminId, $reason): SellerApplication {
            $application->decided_by_admin_id = $decidedByAdminId;
            $application->save();

            $rejected = ($this->transition)(
                application: $application,
                to: SellerApplicationStatus::Rejected,
                actorType: 'admin',
                actorId: $decidedByAdminId,
                reason: $reason,
            );

            ($this->audit)(
                action: 'seller.rejected',
                actorType: 'admin',
                actorId: $decidedByAdminId,
                subjectType: SellerApplication::class,
                subjectId: $application->id,
                reason: $reason,
            );

            return $rejected;
        });
    }
}
