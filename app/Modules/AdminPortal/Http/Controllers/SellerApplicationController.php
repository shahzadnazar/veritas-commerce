<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\AdminPortal\Http\Requests\DecisionRequest;
use App\Modules\AdminPortal\Queries\SellerApplicationQueue;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Sellers\Actions\ApproveSellerApplication;
use App\Modules\Sellers\Actions\RejectSellerApplication;
use App\Modules\Sellers\Actions\RequestApplicationChanges;
use App\Modules\Sellers\Actions\TransitionSellerApplication;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seller application review.
 *
 * Every action re-checks its permission against the acting administrator
 * rather than trusting that the route middleware ran — defence in depth,
 * and the thing the route×role tests actually assert.
 */
final class SellerApplicationController
{
    public function __construct(
        private readonly SellerApplicationQueue $queue,
        private readonly TransitionSellerApplication $transition,
        private readonly ApproveSellerApplication $approve,
        private readonly RejectSellerApplication $reject,
        private readonly RequestApplicationChanges $requestChanges,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize($request, AdminPermission::SellerApplicationView);

        $applications = ($this->queue)(
            status: $request->string('status')->toString() ?: null,
            search: $request->string('search')->toString() ?: null,
        );

        return Inertia::render('Sellers/Applications', [
            'applications' => [
                'data' => array_map(
                    static fn (SellerApplication $application): array => [
                        'publicId' => $application->public_id,
                        'reference' => $application->reference,
                        'legalName' => $application->legal_name,
                        'tradingName' => $application->trading_name,
                        'contactEmail' => $application->contact_email,
                        'status' => $application->status->value,
                        'submittedAt' => $application->submitted_at?->toDateString(),
                        'reviewer' => $application->reviewer?->name,
                    ],
                    $applications->items(),
                ),
                'currentPage' => $applications->currentPage(),
                'lastPage' => $applications->lastPage(),
                'total' => $applications->total(),
            ],
            'filters' => [
                'status' => $request->string('status')->toString(),
                'search' => $request->string('search')->toString(),
            ],
            'statuses' => array_map(
                static fn (SellerApplicationStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                SellerApplicationStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, string $publicId): Response
    {
        $this->authorize($request, AdminPermission::SellerApplicationView);

        $application = SellerApplication::query()
            ->with(['reviewer', 'applicant', 'documents', 'events'])
            ->where('public_id', $publicId)
            ->firstOrFail();

        $admin = $this->admin($request);
        $canSeeSensitive = $admin->role->can(AdminPermission::SellerViewSensitive);

        return Inertia::render('Sellers/ApplicationDetail', [
            'application' => [
                'publicId' => $application->public_id,
                'reference' => $application->reference,
                'status' => $application->status->value,
                'legalName' => $application->legal_name,
                'tradingName' => $application->trading_name,
                'businessType' => $application->business_type,
                // The tax ID is sensitive: a support agent reading an
                // application does not need it, so they do not receive it.
                'taxId' => $canSeeSensitive ? $application->tax_id : null,
                'address' => trim(implode(', ', array_filter([
                    $application->address_line1,
                    $application->address_city,
                    $application->address_state,
                    $application->address_postcode,
                ]))),
                'website' => $application->website,
                'contactName' => $application->contact_name,
                'contactEmail' => $application->contact_email,
                'contactPhone' => $application->contact_phone,
                'intendedCategories' => $application->intended_categories ?? [],
                'expectedCatalogueType' => $application->expected_catalogue_type,
                'blurb' => $application->blurb,
                'operationalNotes' => $application->operational_notes,
                'decisionReason' => $application->decision_reason,
                'submittedAt' => $application->submitted_at?->toDayDateTimeString(),
                'reviewer' => $application->reviewer?->name,
            ],
            'documents' => $application->documents->map(fn ($document): array => [
                'kind' => $document->kind,
                'originalName' => $document->original_name,
                'uploadedAt' => $document->uploaded_at?->toDateString(),
            ])->all(),
            'history' => $application->events->sortBy('id')->values()->map(fn (SellerApplicationEvent $event): array => [
                'fromStatus' => $event->from_status,
                'toStatus' => $event->to_status,
                'actorType' => $event->actor_type,
                'reason' => $event->reason,
                'at' => $event->created_at?->toDayDateTimeString(),
            ])->all(),
            'can' => [
                'review' => $admin->role->can(AdminPermission::SellerApplicationReview),
                'approve' => $admin->role->can(AdminPermission::SellerApprove),
                'reject' => $admin->role->can(AdminPermission::SellerReject),
            ],
        ]);
    }

    public function beginReview(Request $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::SellerApplicationReview);

        $admin = $this->admin($request);
        $application = $this->find($publicId);

        $application->reviewer_admin_id = $admin->id;
        $application->save();

        ($this->transition)(
            application: $application,
            to: SellerApplicationStatus::UnderReview,
            actorType: 'admin',
            actorId: $admin->id,
        );

        return back()->with('success', 'Review started.');
    }

    public function approve(Request $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::SellerApprove);

        $admin = $this->admin($request);
        ($this->approve)($this->find($publicId), $admin->id);

        return back()->with('success', 'Seller approved.');
    }

    public function reject(DecisionRequest $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::SellerReject);

        $admin = $this->admin($request);
        ($this->reject)($this->find($publicId), $admin->id, $request->reason());

        return back()->with('success', 'Application rejected.');
    }

    public function requestChanges(DecisionRequest $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::SellerApplicationReview);

        $admin = $this->admin($request);
        ($this->requestChanges)($this->find($publicId), $admin->id, $request->reason());

        return back()->with('success', 'Changes requested.');
    }

    private function find(string $publicId): SellerApplication
    {
        return SellerApplication::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->user('admin');

        abort_if(! $admin instanceof AdminUser, 403);

        return $admin;
    }

    private function authorize(Request $request, AdminPermission $permission): void
    {
        abort_unless($this->admin($request)->role->can($permission), 403);
    }
}
