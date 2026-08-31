<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Notifications\SellerApplicationDecided;
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

            // The applicant is told what to change, verbatim: a request
            // for changes that does not say what to change is a rejection
            // in slow motion.
            DB::afterCommit(function () use ($updated, $reason): void {
                $updated->applicant()->first()?->notify(new SellerApplicationDecided(
                    reference: $updated->reference,
                    status: SellerApplicationStatus::ChangesRequested,
                    reason: $reason,
                ));
            });

            return $updated;
        });
    }
}
