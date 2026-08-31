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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account already exists for that address. Sign in instead, or reset your password.',
            'password.uncompromised' => 'That password has appeared in a known data breach. Choose another.',
        ];
    }
}
