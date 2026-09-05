<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Http\Controllers;

use App\Modules\Reviews\Actions\EditReview;
use App\Modules\Reviews\Actions\ModerateReview;
use App\Modules\Reviews\Actions\SubmitReview;
use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Exceptions\ReviewRefused;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Support\ReviewText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer writing, editing or withdrawing their own review.
 *
 * Notice what this controller does **not** accept: no `verified_purchase`,
 * no `status`, no order item, no seller. §3 and §4 in the shape of the
 * request — the verified badge is established by SubmitReview from the
 * order record, and there is nowhere in this signature for a browser to
 * assert it. A hostile client sending `verified_purchase=true` sends a
 * field the validator drops and the action has no parameter for.
 *
 * Editing and withdrawing are scoped by author inside the actions, and the
 * lookup here is by public id, so a guessed review belongs to nobody.
 */
final class ProductReviewController
{
    public function __construct(
        private readonly SubmitReview $submit,
        private readonly EditReview $edit,
        private readonly ModerateReview $moderate,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $userId = $this->userId($request);
        $validated = $this->validated($request);

        $productId = DB::table('products')
            ->where('public_id', $validated['product'])
            ->value('id');

        if ($productId === null) {
            throw ValidationException::withMessages(['product' => 'That product could not be found.']);
        }

        try {
            ($this->submit)(
                userId: $userId,
                productId: (int) $productId,
                rating: $validated['rating'],
                body: $validated['body'],
                title: $validated['title'],
            );
        } catch (ReviewRefused $refusal) {
            throw ValidationException::withMessages(['review' => $refusal->getMessage()]);
        }

        return back()->with('status', 'Thanks — your review is live.');
    }

    public function update(Request $request, string $review): RedirectResponse
    {
        $userId = $this->userId($request);
        $model = $this->authoredBy($review, $userId);
        $validated = $this->validated($request, requireProduct: false);

        try {
            ($this->edit)(
                review: $model,
                userId: $userId,
                rating: $validated['rating'],
                body: $validated['body'],
                title: $validated['title'],
            );
        } catch (ReviewRefused $refusal) {
            throw ValidationException::withMessages(['review' => $refusal->getMessage()]);
        }

        return back()->with('status', 'Your review has been updated.');
    }

    public function destroy(Request $request, string $review): RedirectResponse
    {
        $userId = $this->userId($request);
        $model = $this->authoredBy($review, $userId);

        try {
            $this->moderate->withdraw($model, ReviewActor::customer($userId));
        } catch (ReviewRefused $refusal) {
            throw ValidationException::withMessages(['review' => $refusal->getMessage()]);
        }

        return back()->with('status', 'Your review has been removed.');
    }

    /**
     * The review, if this customer wrote it.
     *
     * 404 rather than 403 for somebody else's review: telling an attacker
     * that a review exists but is not theirs is telling them something.
     */
    private function authoredBy(string $publicId, int $userId): ProductReview
    {
        /** @var ProductReview|null $review */
        $review = ProductReview::query()
            ->where('public_id', $publicId)
            ->where('user_id', $userId)
            ->first();

        abort_if($review === null, 404);

        return $review;
    }

    /**
     * @return array{product: string, rating: int, body: string, title: string|null}
     */
    private function validated(Request $request, bool $requireProduct = true): array
    {
        $rules = [
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'max:'.(ReviewText::MAX_BODY * 2)],
            'title' => ['nullable', 'string', 'max:'.(ReviewText::MAX_TITLE * 2)],
        ];

        if ($requireProduct) {
            $rules['product'] = ['required', 'string', 'max:64'];
        }

        $validated = $request->validate($rules);

        // The generous max lengths above are a first line only: the body
        // is cleaned of markup before it is measured, so a 4,000-character
        // review made of <b> tags is not a 4,000-character review. That
        // decision lives in ReviewText, not here.
        return [
            'product' => isset($validated['product']) ? (string) $validated['product'] : '',
            'rating' => (int) $validated['rating'],
            'body' => (string) $validated['body'],
            'title' => isset($validated['title']) && is_string($validated['title']) ? $validated['title'] : null,
        ];
    }

    private function userId(Request $request): int
    {
        return (int) $request->user('web')?->getAuthIdentifier();
    }
}
