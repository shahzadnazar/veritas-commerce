<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Exceptions\AttributeValidationFailed;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\AttributeOption;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Writes a product's specifications, having first checked they are ones
 * the category actually asks for.
 *
 * This is where the category schema stops being documentation. An
 * attribute the category never declared is refused rather than quietly
 * stored, a required one that is missing fails the whole save, and an
 * enumerated value that is not on the list does not become a new option by
 * accident.
 */
final class SaveAttributeValues
{
    /**
     * @param  array<string, mixed>  $values  attribute code => value
     *
     * @throws AttributeValidationFailed
     */
    public function __invoke(
        Product $product,
        array $values,
        ?ProductVariant $variant = null,
        bool $requireComplete = true,
    ): void {
        $category = $product->category;

        if ($category === null) {
            throw new AttributeValidationFailed(['category' => 'This product has no category, so it has no specification schema.']);
        }

        $schema = $this->schemaFor($category);
        $errors = [];

        foreach (array_keys($values) as $code) {
            if (! isset($schema[$code])) {
                $errors[$code] = "The {$category->name} category does not use an attribute called '{$code}'.";
            }
        }

        if ($requireComplete) {
            foreach ($schema as $code => $attribute) {
                $required = (bool) ($attribute->getRelationValue('categories')?->first()?->pivot->is_required ?? false);

                if ($required && ! $this->isProvided($values[$code] ?? null)) {
                    $errors[$code] = "{$attribute->name} is required for {$category->name}.";
                }
            }
        }

        if ($errors !== []) {
            throw new AttributeValidationFailed($errors);
        }

        DB::transaction(function () use ($product, $values, $variant, $schema): void {
            foreach ($values as $code => $value) {
                $attribute = $schema[$code];

                if (! $this->isProvided($value)) {
                    // An emptied field removes the specification rather
                    // than storing a blank one.
                    ProductAttributeValue::query()
                        ->where('product_id', $product->id)
                        ->where('attribute_id', $attribute->id)
                        ->where('product_variant_id', $variant?->id)
                        ->delete();

                    continue;
                }

                ProductAttributeValue::query()->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'attribute_id' => $attribute->id,
                        'product_variant_id' => $variant?->id,
                    ],
                    $this->valueRow($attribute, $value),
                );
            }
        });
    }

    /**
     * The complete value row: the value in the column its type belongs in,
     * and every other column explicitly null.
     *
     * All six are written every time, so changing an attribute's type
     * cannot leave the old value behind in a column nothing reads any more.
     *
     * @return array{
     *     value_text: string|null,
     *     value_int: int|null,
     *     value_decimal: string|null,
     *     value_boolean: bool|null,
     *     value_date: string|null,
     *     attribute_option_id: int|null,
     * }
     *
     * @throws AttributeValidationFailed
     */
    private function valueRow(Attribute $attribute, mixed $value): array
    {
        $empty = [
            'value_text' => null,
            'value_int' => null,
            'value_decimal' => null,
            'value_boolean' => null,
            'value_date' => null,
            'attribute_option_id' => null,
        ];

        if ($attribute->data_type->isEnumerated()) {
            $option = AttributeOption::query()
                ->where('attribute_id', $attribute->id)
                ->where('value', (string) $value)
                ->first();

            if ($option === null) {
                throw new AttributeValidationFailed([
                    $attribute->code => "'{$value}' is not one of the values {$attribute->name} allows.",
                ]);
            }

            return [...$empty, 'attribute_option_id' => $option->id];
        }

        return match ($attribute->data_type) {
            AttributeType::Integer => [...$empty, 'value_int' => $this->asInteger($attribute, $value)],
            AttributeType::Decimal => [...$empty, 'value_decimal' => $this->asDecimal($attribute, $value)],
            AttributeType::Boolean => [...$empty, 'value_boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN)],
            AttributeType::Date => [...$empty, 'value_date' => (string) $value],
            default => [...$empty, 'value_text' => (string) $value],
        };
    }

    private function asInteger(Attribute $attribute, mixed $value): int
    {
        if (! is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
            throw new AttributeValidationFailed([$attribute->code => "{$attribute->name} must be a whole number."]);
        }

        return (int) $value;
    }

    private function asDecimal(Attribute $attribute, mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new AttributeValidationFailed([$attribute->code => "{$attribute->name} must be a number."]);
        }

        return (string) $value;
    }

    private function isProvided(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * The category's attributes, keyed by code.
     *
     * @return array<string, Attribute>
     */
    private function schemaFor(Category $category): array
    {
        $schema = [];

        foreach ($category->effectiveAttributes() as $attribute) {
            $schema[$attribute->code] = $attribute;
        }

        return $schema;
    }
}
