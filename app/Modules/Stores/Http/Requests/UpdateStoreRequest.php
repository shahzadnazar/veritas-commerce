<?php

declare(strict_types=1);

namespace App\Modules\Stores\Http\Requests;

use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Modules\Stores\Models\Store;
use App\Modules\Stores\Support\StoreSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Store configuration input.
 *
 * Authorisation happens here as well as in the route middleware, and it
 * asks the actor's membership — never a seller id from the payload.
 */
final class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CurrentSeller::can(SellerPermission::StoreManage);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge(['slug' => StoreSlug::normalise((string) $this->input('slug'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $storeId = $this->currentStoreId();

        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'slug' => [
                'required', 'string', 'min:3', 'max:40',
                // Global uniqueness: two sellers cannot share an address.
                Rule::unique('stores', 'slug')->ignore($storeId),
                Rule::notIn(StoreSlug::RESERVED),
                fn (string $attribute, mixed $value, callable $fail) => $this->assertWellFormed($value, $fail),
                fn (string $attribute, mixed $value, callable $fail) => $this->assertNotAnotherStoresOldSlug($value, $storeId, $fail),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'support_email' => ['nullable', 'email:rfc', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'shipping_policy' => ['nullable', 'string', 'max:2000'],
            'return_policy' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'business_city' => ['nullable', 'string', 'max:120'],
            'business_state' => ['nullable', 'string', 'max:64'],
            'business_country' => ['nullable', 'string', 'size:2'],
            'is_open' => ['boolean'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'banner' => ['nullable', 'image', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.unique' => 'Another store already uses that address.',
            'slug.not_in' => 'That address is reserved by the marketplace.',
        ];
    }

    private function currentStoreId(): ?int
    {
        $sellerId = CurrentSeller::id();

        if ($sellerId === null) {
            return null;
        }

        $id = Store::query()->where('seller_account_id', $sellerId)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function assertWellFormed(mixed $value, callable $fail): void
    {
        if (! StoreSlug::isWellFormed((string) $value)) {
            $fail('The address may use lowercase letters, numbers and hyphens only.');
        }
    }

    /**
     * A slug some other store used still redirects there, so handing it to
     * a different seller would quietly hijack that redirect.
     */
    private function assertNotAnotherStoresOldSlug(mixed $value, ?int $storeId, callable $fail): void
    {
        $claimed = DB::table('store_slug_history')
            ->where('old_slug', $value)
            ->when($storeId !== null, fn ($query) => $query->where('store_id', '!=', $storeId))
            ->exists();

        if ($claimed) {
            $fail('That address belonged to another store and still redirects there.');
        }
    }
}
