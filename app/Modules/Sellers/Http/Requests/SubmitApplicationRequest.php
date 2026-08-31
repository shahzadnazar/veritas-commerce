<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any signed-in customer may apply; whether they are approved is a
        // separate question entirely.
        return $this->user('web') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'min:2', 'max:160'],
            'trading_name' => ['required', 'string', 'min:2', 'max:60'],
            'business_type' => ['required', 'string', 'max:60'],
            'tax_id' => ['required', 'string', 'max:64'],
            'address_line1' => ['required', 'string', 'max:160'],
            'address_line2' => ['nullable', 'string', 'max:160'],
            'address_city' => ['required', 'string', 'max:120'],
            'address_state' => ['required', 'string', 'max:64'],
            'address_postcode' => ['required', 'string', 'max:32'],
            'address_country' => ['nullable', 'string', 'size:2'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_role' => ['nullable', 'string', 'max:80'],
            'contact_email' => ['required', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:255'],
            'intended_categories' => ['nullable', 'array', 'max:10'],
            'intended_categories.*' => ['string', 'max:60'],
            'expected_catalogue_type' => ['nullable', 'string', 'max:60'],
            'planned_listings' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'blurb' => ['required', 'string', 'min:20', 'max:1000'],
            'operational_notes' => ['nullable', 'string', 'max:1000'],
            'terms_accepted' => ['accepted'],
        ];
    }

    /** @return array<string, mixed> */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();
        unset($data['terms_accepted']);
        $data['terms_accepted_at'] = now();

        return $data;
    }
}
