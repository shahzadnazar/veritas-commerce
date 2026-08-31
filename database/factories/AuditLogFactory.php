<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'actor_type' => 'admin',
            'action' => 'test.event',
            'created_at' => now(),
        ];
    }
}
