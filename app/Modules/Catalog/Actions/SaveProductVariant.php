<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Exceptions\AttributeValidationFailed;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates one variant of a product.
 *
 * A variant is a point in the space the category's variant-defining
 * attributes describe: "Colour = Black, Capacity = 256GB". Its axes come
 * from the category schema, not from free text, so every product in a
 * category varies along the same dimensions and can be compared.
 */
final class SaveProductVariant
{
    public function __construct(private readonly SaveAttributeValues $saveValues) {}

    /**
     * @param  array<string, string>  $options  attribute code => value
     *
     * @throws AttributeValidationFailed
     */
    public function __invoke(Product $product, array $options, ?ProductVariant $variant = null): ProductVariant
    {
        $axes = $this->variantAxes($product);

        if ($axes === []) {
            throw new AttributeValidationFailed([
                'variants' => 'This category defines no variant-defining attributes, so its products cannot have variants.',
            ]);
        }

        $errors = [];

        foreach ($axes as $code => $attribute) {
            if (! isset($options[$code]) || $options[$code] === '') {
                $errors[$code] = "{$attribute->name} is one of this category's variant axes, so every variant must state it.";

                continue;
            }

            if (! $attribute->permits($options[$code])) {
                $errors[$code] = "'{$options[$code]}' is not one of the values {$attribute->name} allows.";
            }
        }

        foreach (array_keys($options) as $code) {
            if (! isset($axes[$code])) {
                $errors[$code] = "'{$code}' does not vary in this category, so it belongs on the product rather than the variant.";
            }
        }

        if ($errors !== []) {
            throw new AttributeValidationFailed($errors);
        }

        return DB::transaction(function () use ($product, $options, $variant, $axes): ProductVariant {
            $signature = $this->signature($options);

            $variant ??= new ProductVariant(['product_id' => $product->id]);
            $variant->product_id = $product->id;
            $variant->name = $this->name($options, $axes);
            $variant->option_values = $options;
            $variant->option_signature = $signature;
            $variant->is_active = true;

            try {
                $variant->save();
            } catch (UniqueConstraintViolationException) {
                // The index is the guarantee. Two variants at the same
                // point would make the picker ambiguous and the offers
                // against them unattributable.
                throw new AttributeValidationFailed([
                    'variants' => "This product already has a variant for {$variant->name}.",
                ]);
            }

            // The same values are also written as attribute rows, so a
            // variant's specifications are queryable alongside every other
            // product's rather than only readable as a label.
            ($this->saveValues)($product, $options, $variant, requireComplete: false);

            return $variant;
        });
    }

    /**
     * A stable, order-independent fingerprint of the combination.
     *
     * Sorted by attribute code so "colour=black;capacity=256" and
     * "capacity=256;colour=black" are one point, not two.
     *
     * @param  array<string, string>  $options
     */
    private function signature(array $options): string
    {
        $normalised = array_map(
            static fn (string $value): string => mb_strtolower(trim($value)),
            $options,
        );

        ksort($normalised);

        $pairs = [];

        foreach ($normalised as $code => $value) {
            $pairs[] = $code.'='.$value;
        }

        return implode(';', $pairs);
    }

    /**
     * @param  array<string, string>  $options
     * @param  array<string, Attribute>  $axes
     */
    private function name(array $options, array $axes): string
    {
        $parts = [];

        foreach ($axes as $code => $attribute) {
            $value = $options[$code] ?? null;

            if ($value === null) {
                continue;
            }

            $option = $attribute->options->firstWhere('value', $value);
            $parts[] = $option->label ?? $value;
        }

        return implode(' / ', $parts);
    }

    /**
     * The category's variant axes, keyed by attribute code.
     *
     * @return array<string, Attribute>
     */
    private function variantAxes(Product $product): array
    {
        $category = $product->category;

        if ($category === null) {
            return [];
        }

        $axes = [];

        foreach ($category->effectiveAttributes() as $attribute) {
            $pivotSaysVariant = (bool) ($attribute->getRelationValue('categories')?->first()?->pivot->is_variant_defining ?? false);

            if (($attribute->is_variant_defining || $pivotSaysVariant) && $attribute->data_type->canDefineVariants()) {
                $axes[$attribute->code] = $attribute;
            }
        }

        return $axes;
    }
}
