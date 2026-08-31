<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A negative decision must carry a reason.
 *
 * Enforced here and again in the transition guard, because the reason is
 * shown to the applicant verbatim and kept forever: "rejected, no reason
 * given" is not an outcome anyone can act on or appeal.
 */
final class DecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required — the applicant is shown this verbatim.',
            'reason.min' => 'Give the applicant enough to act on: at least ten characters.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated()['reason']);
    }
}
