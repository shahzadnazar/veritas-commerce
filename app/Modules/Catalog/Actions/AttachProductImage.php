<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Catalog\Jobs\ProcessProductImage;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Adds an image to a canonical product.
 *
 * The request does two cheap things — validate the bytes, put them
 * somewhere — and hands the expensive part to a queue. Deriving thumbnails
 * from a 12-megapixel photograph inside an HTTP request is how an upload
 * form times out on exactly the connection least able to retry it.
 *
 * The row exists immediately in a `pending` state, so the seller sees
 * their upload straight away and the storefront knows not to show it yet.
 */
final class AttachProductImage
{
    public function __construct(
        private readonly ObjectStore $objects,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function __invoke(
        Product $product,
        UploadedFile $file,
        string $actorType,
        int $actorId,
        ?string $altText = null,
    ): ProductMedia {
        // Validation and storage happen before the transaction: writing
        // bytes is not something a rollback can undo, and holding a
        // transaction open across an upload to remote storage exhausts
        // connections under load.
        $stored = $this->objects->put(
            $file,
            "products/{$product->id}/images",
            // Product photography is public and CDN-fronted. It is also
            // full colour: the monochrome of the design system is chrome,
            // never the goods.
            Visibility::Public,
        );

        $media = DB::transaction(function () use ($product, $stored, $altText, $actorType, $actorId): ProductMedia {
            $isFirst = ! ProductMedia::query()->where('product_id', $product->id)->exists();

            $media = ProductMedia::query()->create([
                'product_id' => $product->id,
                'disk' => $stored->disk,
                'path' => $stored->key,
                'mime' => $stored->mime,
                'bytes' => $stored->bytes,
                'width' => $stored->width,
                'height' => $stored->height,
                'checksum' => $stored->checksum,
                'alt_text' => $altText,
                'position' => (int) ProductMedia::query()->where('product_id', $product->id)->max('position') + 1,
                // The first image a product gets leads by default; a
                // gallery with no first image has to pick one arbitrarily.
                'is_primary' => $isFirst,
                'processing_state' => ProductMedia::STATE_PENDING,
            ]);

            ($this->audit)(
                action: 'catalogue.product.image_added',
                actorType: $actorType,
                actorId: $actorId,
                subjectType: Product::class,
                subjectId: $product->id,
                changes: ['media_public_id' => $media->public_id, 'mime' => $stored->mime, 'bytes' => $stored->bytes],
            );

            return $media;
        });

        // After commit: a worker must never be handed the id of a row a
        // rollback removed.
        DB::afterCommit(static function () use ($media): void {
            ProcessProductImage::dispatch($media->id);
        });

        return $media;
    }
}
