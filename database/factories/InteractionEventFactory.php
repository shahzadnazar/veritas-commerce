<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Models\InteractionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InteractionEvent> */
final class InteractionEventFactory extends Factory
{
    protected $model = InteractionEvent::class;

    public function definition(): array
    {
        return [
            'anonymous_session_id' => $this->faker->uuid(),
            'event_type' => InteractionEventType::ProductViewed->value,
            'context' => 'search',
            'created_at' => now(),
        ];
    }
}
