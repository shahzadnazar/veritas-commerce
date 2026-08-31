<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Models;

use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Support\HasPublicId;
use Database\Factories\SellerApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One form, three sections, no wizard — multi-step onboarding is abandoned.
 *
 * Re-applying edits this record rather than creating a duplicate, so the
 * APP- reference is stable across attempts and a rejection reason stays
 * attached to the history.
 */
final class SellerApplication extends Model
{
    /** @use HasFactory<SellerApplicationFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'seller_applications';

    protected $fillable = [
        'reference', 'user_id', 'legal_name', 'trading_name', 'business_type', 'tax_id',
        'address_line1', 'address_line2', 'address_city', 'address_state',
        'address_postcode', 'address_country', 'contact_name', 'contact_role',
        'contact_email', 'contact_phone', 'primary_category_id', 'planned_listings',
        'existing_site', 'blurb', 'status', 'terms_accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SellerApplicationStatus::class,
            'tax_id' => 'encrypted',
            'terms_accepted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
