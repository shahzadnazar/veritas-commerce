<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<AdminUser> */
final class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->companyEmail(),
            'password' => Hash::make('password'),
            'name' => $this->faker->name(),
            'role' => AdminRole::Operations->value,
            'two_factor_confirmed_at' => now(),
        ];
    }

    public function role(AdminRole $role): self
    {
        return $this->state(fn (): array => ['role' => $role->value]);
    }
}
