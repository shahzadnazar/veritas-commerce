<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SellerApplicationDocument> */
final class SellerApplicationDocumentFactory extends Factory
{
    protected $model = SellerApplicationDocument::class;

    public function definition(): array
    {
        return [
            'seller_application_id' => SellerApplication::factory(),
            'kind' => 'business_registration',
            'disk' => 'local',
            'path' => 'seller-documents/'.$this->faker->uuid().'.pdf',
            'original_name' => 'registration.pdf',
            'mime' => 'application/pdf',
            'bytes' => 12_345,
            'uploaded_at' => now(),
        ];
    }
}
