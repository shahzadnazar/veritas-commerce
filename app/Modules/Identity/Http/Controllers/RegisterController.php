<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\RegisterCustomer;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Support\Guards;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class RegisterController
{
    public function __construct(private readonly RegisterCustomer $register) {}

    public function show(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = ($this->register)($request->validated());

        Guards::session('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
