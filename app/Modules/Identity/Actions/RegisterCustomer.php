<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Creates a customer identity.
 *
 * A user is an identity, not a role: this same record later gains seller
 * memberships without a second account or a second password.
 */
final class RegisterCustomer
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /** @param  array{first_name: string, last_name: string, email: string, password: string, marketing_opt_in?: bool}  $attributes */
    public function __invoke(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = User::query()->create([
                'first_name' => $attributes['first_name'],
                'last_name' => $attributes['last_name'],
                'email' => strtolower($attributes['email']),
                'password' => $attributes['password'],
                'marketing_opt_in' => $attributes['marketing_opt_in'] ?? false,
            ]);

            // Fires Laravel's verification notification, queued.
            Event::dispatch(new Registered($user));

            ($this->audit)(
                action: 'customer.registered',
                actorType: 'customer',
                actorId: $user->id,
                subjectType: User::class,
                subjectId: $user->id,
            );

            return $user;
        });
    }
}
