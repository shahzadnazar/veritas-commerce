<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\ProductReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductReview> */
final class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => User::factory(),
            'rating' => $this->faker->numberBetween(1, 5),
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'status' => ReviewStatus::Published->value,
            /*
             * Unverified by default, deliberately.
             *
             * A factory that made every review verified would let a test
             * assert something about the verified badge without ever
             * exercising the evidence that produces it. Tests that want a
             * verified review build the purchase and go through
             * SubmitReview, which is the only thing that can set it.
             */
            'verified_purchase' => false,
            'published_at' => now(),
        ];
    }

    public function hidden(): self
    {
        return $this->state(fn (): array => [
            'status' => ReviewStatus::Hidden->value,
            'hidden_at' => now(),
            'moderation_reason' => 'Off topic.',
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (): array => [
            'status' => ReviewStatus::Rejected->value,
            'rejected_at' => now(),
            'moderation_reason' => 'Not about this product.',
        ]);
    }

    public function withdrawn(): self
    {
        return $this->state(fn (): array => [
            'status' => ReviewStatus::Withdrawn->value,
            'withdrawn_at' => now(),
        ]);
    }

    public function rated(int $rating): self
    {
        return $this->state(fn (): array => ['rating' => $rating]);
    }
}
