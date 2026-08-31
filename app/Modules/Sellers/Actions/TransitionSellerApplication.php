<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Exceptions\InvalidApplicationTransition;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way an application changes state.
 *
 * The state machine on the enum decides what is legal; this enforces it and
 * writes the history row in the same transaction, so a state change without
 * a corresponding event is impossible rather than merely discouraged.
 */
final class TransitionSellerApplication
{
    public function __invoke(
        SellerApplication $application,
        SellerApplicationStatus $to,
        string $actorType,
        ?int $actorId = null,
        ?string $reason = null,
    ): SellerApplication {
        $from = $application->status;

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw InvalidApplicationTransition::between($from, $to);
        }

        if ($to->requiresReason() && trim((string) $reason) === '') {
            throw InvalidApplicationTransition::reasonRequired($to);
        }

        return DB::transaction(function () use ($application, $from, $to, $actorType, $actorId, $reason): SellerApplication {
            $application->status = $to;

            if ($to === SellerApplicationStatus::Submitted) {
                $application->submitted_at = Carbon::now();
            }

            if ($to === SellerApplicationStatus::UnderReview) {
                $application->review_started_at = Carbon::now();
            }

            if ($to->isTerminal()) {
                $application->decided_at = Carbon::now();
                $application->decision_reason = $reason;
            }

            if ($to === SellerApplicationStatus::ChangesRequested) {
                $application->decision_reason = $reason;
            }

            $application->save();

            SellerApplicationEvent::query()->create([
                'seller_application_id' => $application->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
                'created_at' => Carbon::now(),
            ]);

            return $application;
        });
    }
}
