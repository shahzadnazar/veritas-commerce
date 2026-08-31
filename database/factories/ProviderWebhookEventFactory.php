<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Payments\Models\ProviderWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProviderWebhookEvent> */
final class ProviderWebhookEventFactory extends Factory
{
    protected $model = ProviderWebhookEvent::class;

    public function definition(): array
    {
        return [
            'provider' => 'fake',
            'event_id' => 'evt_'.$this->faker->unique()->uuid(),
            'type' => 'payment.captured',
            'payload' => [],
            'received_at' => now(),
        ];
    }
}
