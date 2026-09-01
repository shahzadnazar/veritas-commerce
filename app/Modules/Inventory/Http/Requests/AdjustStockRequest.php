<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\InventoryMovementReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A stock correction, validated before it reaches the domain.
 *
 * The reason must be one a person is allowed to choose: the system's own
 * reasons — a sale, a hold, an expiry — are written by the code that
 * performs them and cannot be claimed by a form post.
 */
final class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorisation is the controller's, which knows whose stock this
        // is. This object only shapes the input.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'change' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'reason' => [
                'required',
                Rule::enum(InventoryMovementReason::class)->only(
                    array_values(array_filter(
                        InventoryMovementReason::cases(),
                        static fn (InventoryMovementReason $reason): bool => $reason->isSellerSelectable(),
                    )),
                ),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'change.not_in' => 'An adjustment of zero changes nothing. Say how many units moved.',
            'reason.Illuminate\Validation\Rules\Enum' => 'Choose a reason for the change.',
        ];
    }

    public function movementReason(): InventoryMovementReason
    {
        return InventoryMovementReason::from((string) $this->string('reason'));
    }

    public function change(): int
    {
        return (int) $this->integer('change');
    }

    public function note(): ?string
    {
        $note = trim((string) $this->string('note'));

        return $note === '' ? null : $note;
    }
}
