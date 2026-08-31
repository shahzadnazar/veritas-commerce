<?php

declare(strict_types=1);

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;

/*
 * Two authentication realms.
 *
 * Customers and sellers share the `web` guard: a seller is a person who also
 * shops, and one human should not hold two passwords. A user gains seller
 * capability through a membership, not through a second account.
 *
 * Platform staff use a separate guard, table, session cookie and a far
 * shorter idle expiry, so a stolen customer session can never be escalated
 * toward admin.
 */
return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => AdminUser::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
