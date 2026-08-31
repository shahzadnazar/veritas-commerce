<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\AllocateReference;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Models\SellerApplication;
use Illuminate\Support\Facades\DB;

/**
 * Submits, or re-submits, one applicant's application.
 *
 * Re-applying edits the same record rather than creating a duplicate, so
 * the APP- reference is stable across attempts and the reviewer sees the
 * whole conversation — including the reason changes were asked for — in
 * one history.
 */
final class SubmitSellerApplication
{
    public function __construct(
        private readonly TransitionSellerApplication $transition,
        private readonly AllocateReference $references,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param  array<string, mixed>  $attributes */
    public function __invoke(User $user, array $attributes): SellerApplication
    {
        return DB::transaction(function () use ($user, $attributes): SellerApplication {
            $application = SellerApplication::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [
                    SellerApplicationStatus::Draft->value,
                    SellerApplicationStatus::ChangesRequested->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($application === null) {
                $application = new SellerApplication;
                $application->reference = $this->references->applicationReference();
                $application->user_id = $user->id;
                $application->status = SellerApplicationStatus::Draft;
            }

            $application->fill($attributes);
            $application->save();

            ($this->transition)(
                application: $application,
                to: SellerApplicationStatus::Submitted,
                actorType: 'customer',
                actorId: $user->id,
            );

            ($this->audit)(
                action: 'seller.application.submitted',
                actorType: 'customer',
                actorId: $user->id,
                subjectType: SellerApplication::class,
                subjectId: $application->id,
                changes: ['reference' => $application->reference],
            );

            return $application;
        });
    }
}
