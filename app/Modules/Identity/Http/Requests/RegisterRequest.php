<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Registration input.
 *
 * The password rule is Laravel's, not a hand-rolled policy: eight
 * characters minimum, checked against the breached-password corpus through
 * the k-anonymity API so the password itself never leaves this server.
 */
final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'marketing_opt_in' => ['boolean'],
        ];
    }

    /**
     * The validated input as the registration action's own input shape.
     *
     * The conversion happens here, at the boundary, so the action states
     * exactly what it needs and the controller does not hand it a bag of
     * unknowns and hope.
     *
     * @return array{first_name: string, last_name: string, email: string, password: string, marketing_opt_in: bool}
     */
    public function registration(): array
    {
        $validated = $this->validated();

        return [
            'first_name' => (string) $validated['first_name'],
            'last_name' => (string) $validated['last_name'],
            'email' => (string) $validated['email'],
            'password' => (string) $validated['password'],
            'marketing_opt_in' => (bool) ($validated['marketing_opt_in'] ?? false),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account already exists for that address. Sign in instead, or reset your password.',
            'password.uncompromised' => 'That password has appeared in a known data breach. Choose another.',
        ];
    }
}
