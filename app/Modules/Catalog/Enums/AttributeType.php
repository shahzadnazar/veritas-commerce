<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * What kind of value an attribute holds.
 *
 * The type decides three things at once: which column the value lands in,
 * how it is validated, and how it can be filtered. A decimal screen size
 * has to compare as a number; a colour has to join to a list so renaming
 * "Space Grey" does not orphan ten thousand products.
 */
enum AttributeType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Date = 'date';

    /** The column a value of this type is stored in. */
    public function column(): string
    {
        return match ($this) {
            self::Text => 'value_text',
            self::Integer => 'value_int',
            self::Decimal => 'value_decimal',
            self::Boolean => 'value_boolean',
            self::Date => 'value_date',
            self::Select, self::MultiSelect => 'attribute_option_id',
        };
    }

    /** Whether the value must be one of a declared list. */
    public function isEnumerated(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }

    /** Whether one product may hold several values for this attribute. */
    public function allowsMany(): bool
    {
        return $this === self::MultiSelect;
    }

    /**
     * Only a single-valued, comparable type can distinguish one variant
     * from another: "Black / 256GB" is a coordinate, and a coordinate
     * cannot be a free-text paragraph or a set.
     */
    public function canDefineVariants(): bool
    {
        return match ($this) {
            self::Select, self::Text, self::Integer => true,
            self::Decimal, self::Boolean, self::MultiSelect, self::Date => false,
        };
    }

    /** @return array<int, string> The validation rules a value must satisfy. */
    public function validationRules(): array
    {
        return match ($this) {
            self::Text => ['string', 'max:500'],
            self::Integer => ['integer'],
            self::Decimal => ['numeric'],
            self::Boolean => ['boolean'],
            self::Date => ['date'],
            self::Select, self::MultiSelect => ['string'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Integer => 'Whole number',
            self::Decimal => 'Decimal number',
            self::Boolean => 'Yes or no',
            self::Select => 'One of a list',
            self::MultiSelect => 'Several from a list',
            self::Date => 'Date',
        };
    }
}
