<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Models\SellerApplication;
use Illuminate\Support\Facades\DB;

/**
 * Sends an application back for correction.
 *
 * Distinct from rejection on purpose: telling an applicant they failed
 * when one field needs fixing is both untrue and unrecoverable in the
 * reporting afterwards.
 */
final class RequestApplicationChanges
{
    public function __construct(
        private readonly TransitionSellerApplication $transition,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function __invoke(SellerApplication $application, int $adminId, string $reason): SellerApplication
    {
        return DB::transaction(function () use ($application, $adminId, $reason): SellerApplication {
            $updated = ($this->transition)(
                application: $application,
                to: SellerApplicationStatus::ChangesRequested,
                actorType: 'admin',
                actorId: $adminId,
                reason: $reason,
            );

            ($this->audit)(
                action: 'seller.application.changes_requested',
                actorType: 'admin',
                actorId: $adminId,
                subjectType: SellerApplication::class,
                subjectId: $application->id,
                reason: $reason,
            );

            return $updated;
        });
    }
}
