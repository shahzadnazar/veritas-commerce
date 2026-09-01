<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commercial rules the database enforces, so a path that skips the domain
 * still cannot write nonsense.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One offer per seller per product, for products without variants.
         *
         * The existing unique index covers (seller_account_id,
         * product_variant_id), but Postgres treats NULLs as distinct — so
         * a seller could list the same non-variant product a hundred times
         * and the index would allow every one. This closes that.
         */
        DB::statement(
            'create unique index offers_seller_product_unique
             on offers (seller_account_id, product_id)
             where product_variant_id is null'
        );

        // Money is in minor units and cannot be negative or free. A price
        // of zero is not a giveaway, it is a mistake nobody noticed.
        DB::statement('alter table offers add constraint offers_price_is_positive check (price_minor > 0)');

        // A compare-at price is a claim about a discount. Setting it below
        // the price claims the customer is paying more than usual, which
        // is the opposite of what it means.
        DB::statement(
            'alter table offers add constraint offers_compare_at_above_price check (
                compare_at_price_minor is null or compare_at_price_minor >= price_minor
            )'
        );

        // A variant belongs to one product. An offer naming a variant of a
        // different product would attribute a sale to the wrong catalogue
        // entry — checked in the domain, and here for the paths that are
        // not.
        DB::statement(
            'create or replace function offers_variant_matches_product() returns trigger as $$
             begin
                 if new.product_variant_id is not null and not exists (
                     select 1 from product_variants
                     where id = new.product_variant_id and product_id = new.product_id
                 ) then
                     raise exception \'offer % names a variant that belongs to a different product\', new.id
                         using errcode = \'23514\';
                 end if;

                 return new;
             end;
             $$ language plpgsql'
        );

        DB::statement(
            'create trigger offers_variant_matches_product_check
             before insert or update on offers
             for each row execute function offers_variant_matches_product()'
        );
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists offers_variant_matches_product_check on offers');
        DB::statement('drop function if exists offers_variant_matches_product()');
        DB::statement('alter table offers drop constraint if exists offers_compare_at_above_price');
        DB::statement('alter table offers drop constraint if exists offers_price_is_positive');
        DB::statement('drop index if exists offers_seller_product_unique');
    }
};
