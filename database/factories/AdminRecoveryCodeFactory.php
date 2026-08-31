<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\AdminRecoveryCode;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<AdminRecoveryCode> */
final class AdminRecoveryCodeFactory extends Factory
{
    protected $model = AdminRecoveryCode::class;

    public function definition(): array
    {
        return [
            'admin_user_id' => AdminUser::factory(),
            'code_hash' => Hash::make('TEST1-TEST2'),
            'created_at' => now(),
        ];
    }
}
