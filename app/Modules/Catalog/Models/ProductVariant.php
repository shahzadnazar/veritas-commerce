<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Support\HasPublicId;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A variation axis of the canonical product.
 *
 * "256GB, Black" is a fact about the iPhone, not about one seller's
 * listing — so variants belong to the product and every seller offering
 * that configuration points at the same variant.
 */
final class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'product_variants';

    protected $fillable = ['product_id', 'name', 'option_values', 'gtin', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['option_values' => 'array', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
