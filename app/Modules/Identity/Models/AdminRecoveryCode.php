<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Database\Factories\AdminRecoveryCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use fallback second factor.
 *
 * Only the hash is stored, exactly as for a password: a leaked table must
 * not yield a working second factor. The plaintext exists once, in the
 * response that generates it, and is never recoverable afterwards.
 */
final class AdminRecoveryCode extends Model
{
    /** @use HasFactory<AdminRecoveryCodeFactory> */
    use HasFactory;

    protected $table = 'admin_recovery_codes';

    public $timestamps = false;

    protected $fillable = ['admin_user_id', 'code_hash', 'used_at', 'used_ip', 'created_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
