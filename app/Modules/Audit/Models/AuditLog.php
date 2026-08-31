<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Support\HasPublicId;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** APPEND ONLY. An audit trail that can be edited is not one. */
final class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id',
        'changes', 'reason', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('audit_logs is append-only.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('audit_logs is append-only.');
        });
    }
}
