<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use App\Modules\Sellers\Queries\ResolveDocumentDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A reviewer reading an applicant's paperwork.
 *
 * Gated on seller.view_sensitive, the same permission that reveals a tax
 * ID: a registration certificate carries the same class of information,
 * and a reviewer who may not see one has no business seeing the other.
 */
final class ApplicationDocumentController
{
    public function __construct(private readonly ResolveDocumentDownload $download) {}

    public function show(Request $request, string $publicId): Response
    {
        $admin = $request->user('admin');

        abort_if(! $admin instanceof AdminUser, 403);
        // Checked here as well as on the route: neither alone should be
        // the only thing between a role and a person's paperwork.
        abort_unless($admin->role->can(AdminPermission::SellerViewSensitive), 403);

        $document = SellerApplicationDocument::query()->where('public_id', $publicId)->firstOrFail();

        return ($this->download)($document);
    }
}
