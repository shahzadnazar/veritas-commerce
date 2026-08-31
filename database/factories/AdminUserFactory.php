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
    /** Base32 of "12345678901234567890", the RFC 6238 sample key. */
    public const TEST_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->companyEmail(),
            'password' => Hash::make('password'),
            'name' => $this->faker->name(),
            'role' => AdminRole::Operations->value,
        ];
    }

    public function role(AdminRole $role): self
    {
        return $this->state(fn (): array => ['role' => $role->value]);
    }

    /** An account with a confirmed second factor, using a known secret. */
    public function withTwoFactor(string $secret = self::TEST_SECRET): self
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => $secret,
            'two_factor_enrolled_at' => now(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** Enrolment started but never proved — must not satisfy a login. */
    public function enrollingTwoFactor(string $secret = self::TEST_SECRET): self
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => $secret,
            'two_factor_enrolled_at' => now(),
            'two_factor_confirmed_at' => null,
        ]);
    }
}
