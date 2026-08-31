<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Support\HasPublicId;
use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Platform staff: separate table, separate guard, separate session cookie,
 * shorter idle expiry and mandatory two-factor.
 *
 * An admin can never be signed in as a customer at the same time, so a
 * stolen customer session cannot be escalated toward admin.
 */
final class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'admin_users';

    protected $fillable = ['email', 'password', 'name', 'role'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'role' => AdminRole::class,
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public function can($abilities, $arguments = []): bool
    {
        if ($abilities instanceof AdminPermission) {
            return $this->role->can($abilities);
        }

        if (is_string($abilities)) {
            $permission = AdminPermission::tryFrom($abilities);

            if ($permission !== null) {
                return $this->role->can($permission);
            }
        }

        return parent::can($abilities, $arguments);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}
