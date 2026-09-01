<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Variants as coordinates in the category's variant-defining attributes.
 *
 * A variant holds only what varies. Everything shared — title, brand,
 * description, category, specifications — stays on the product, so
 * "iPhone 17 Pro" is one catalogue entry with six variants rather than six
 * near-identical entries nobody can compare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('sku')->nullable()->after('name');
            $table->string('upc', 12)->nullable()->after('gtin');
            $table->string('ean', 13)->nullable()->after('upc');
            $table->string('mpn')->nullable()->after('ean');

            /*
             * The variant's coordinate, derived from its attribute values
             * and stored so the database can enforce uniqueness.
             *
             * Two variants of one product may not occupy the same point —
             * "Black / 256GB" twice is a data error that would make the
             * variant picker ambiguous and the offers unattributable. A
             * unique index over the attribute rows themselves cannot
             * express "the whole combination", so it is materialised here
             * by the domain that writes it.
             */
            $table->string('option_signature')->nullable()->after('option_values');
        });

        DB::statement(
            'create unique index product_variants_option_signature_unique
             on product_variants (product_id, option_signature)
             where option_signature is not null'
        );

        foreach (['upc', 'ean'] as $identifier) {
            DB::statement(
                "create unique index product_variants_{$identifier}_unique
                 on product_variants ({$identifier}) where {$identifier} is not null"
            );
        }

        // The GTIN column existed from M0 without a constraint.
        DB::statement(
            'create unique index product_variants_gtin_unique
             on product_variants (gtin) where gtin is not null'
        );
    }

    public function down(): void
    {
        foreach (['option_signature', 'upc', 'ean', 'gtin'] as $index) {
            DB::statement("drop index if exists product_variants_{$index}_unique");
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['sku', 'upc', 'ean', 'mpn', 'option_signature']);
        });
    }
};
