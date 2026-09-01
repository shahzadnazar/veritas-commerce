<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers;

use App\Modules\Sellers\Actions\SubmitSellerApplication;
use App\Modules\Sellers\Enums\DocumentKind;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Http\Requests\SubmitApplicationRequest;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use App\Modules\Sellers\Notifications\SellerApplicationDecided;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The applicant's side of the seller application.
 *
 * One form, no wizard: multi-step onboarding is abandoned. The applicant
 * sees their own application and nobody else's — the lookup is by the
 * authenticated user, not by an id in the URL.
 */
final class SellerApplicationController
{
    public function __construct(private readonly SubmitSellerApplication $submit) {}

    public function show(Request $request): Response
    {
        $user = $request->user('web');
        abort_if($user === null, 403);

        $application = SellerApplication::query()
            ->with('documents')
            ->where('user_id', $user->getAuthIdentifier())
            ->latest('id')
            ->first();

        return Inertia::render('Apply', [
            'application' => $application === null ? null : [
                'reference' => $application->reference,
                'status' => $application->status->value,
                'decisionReason' => $application->decision_reason,
                'submittedAt' => $application->submitted_at?->toDayDateTimeString(),
                'editable' => $application->status->isEditableByApplicant(),
                'values' => [
                    'legal_name' => $application->legal_name,
                    'trading_name' => $application->trading_name,
                    'business_type' => $application->business_type,
                    'address_line1' => $application->address_line1,
                    'address_city' => $application->address_city,
                    'address_state' => $application->address_state,
                    'address_postcode' => $application->address_postcode,
                    'contact_name' => $application->contact_name,
                    'contact_email' => $application->contact_email,
                    'contact_phone' => $application->contact_phone,
                    'website' => $application->website,
                    'expected_catalogue_type' => $application->expected_catalogue_type,
                    'blurb' => $application->blurb,
                ],
            ],
            'documents' => $application === null ? [] : $application->documents
                ->map(fn (SellerApplicationDocument $document): array => [
                    'publicId' => $document->public_id,
                    'kind' => $document->kind,
                    'kindLabel' => DocumentKind::tryFrom($document->kind)?->label() ?? $document->kind,
                    'name' => $document->original_name,
                    'bytes' => $document->bytes,
                    'uploadedAt' => $document->uploaded_at->toDayDateTimeString(),
                ])
                ->all(),
            'documentKinds' => DocumentKind::options(),
        ]);
    }

    public function store(SubmitApplicationRequest $request): RedirectResponse
    {
        $user = $request->user('web');
        abort_if($user === null, 403);

        $existing = SellerApplication::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->latest('id')
            ->first();

        // An application already decided, or already with the reviewer, is
        // not open to another submission.
        if ($existing !== null && ! $existing->status->isEditableByApplicant()) {
            return back()->with('error', 'Your application is already with the marketplace team.');
        }

        $application = ($this->submit)($user, $request->validated());

        $user->notify(new SellerApplicationDecided(
            reference: $application->reference,
            status: SellerApplicationStatus::Submitted,
        ));

        return back()->with('success', 'Your application is with the marketplace team.');
    }
}
