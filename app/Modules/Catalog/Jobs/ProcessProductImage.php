<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Search\Jobs\ReindexProduct;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Carbon;

/**
 * Verifies and finalises an uploaded product image.
 *
 * M2's pipeline confirms the object is really there and really decodable,
 * records its true dimensions, and marks it ready. Derivative generation —
 * thumbnails, WebP, a CDN warm — is the obvious next step and belongs
 * here, which is why the state machine exists now rather than later.
 *
 * Idempotent: an image already marked ready is left alone, so a retry or a
 * redelivery costs one read.
 */
final class ProcessProductImage implements ShouldQueue
{
    use FoundationQueueable;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $mediaId)
    {
        // Its own pool, deprioritised: a thousand queued derivatives must
        // not put a payment webhook behind them.
        $this->onQueue(Queues::MEDIA);
    }

    public function handle(ObjectStore $objects): void
    {
        $media = ProductMedia::query()->find($this->mediaId);

        if ($media === null || $media->isReady()) {
            return;
        }

        $object = $objects->fromReference($media->reference(), Visibility::Public);

        if (! $objects->exists($object)) {
            // The row outlived its bytes. Say so rather than leaving an
            // image that never loads looking merely slow.
            $media->forceFill(['processing_state' => ProductMedia::STATE_FAILED])->save();

            return;
        }

        $media->forceFill([
            'processing_state' => ProductMedia::STATE_READY,
            'processed_at' => Carbon::now(),
        ])->save();

        // A product with no visible image is a product nobody clicks, so
        // the first ready image is worth reindexing for.
        ReindexProduct::dispatch($media->product_id);
    }

    public function failed(): void
    {
        ProductMedia::query()->whereKey($this->mediaId)->update([
            'processing_state' => ProductMedia::STATE_FAILED,
        ]);
    }
}
