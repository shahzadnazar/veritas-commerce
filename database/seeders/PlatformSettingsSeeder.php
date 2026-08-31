<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Platform identity and policy as data.
 *
 * Domain, legal entity and branding are never hard-coded in application
 * logic; they are seeded from config (which reads .env) so a development
 * placeholder and a production value differ only in environment.
 */
final class PlatformSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['identity', 'legal_name', config('veritas.identity.legal_name'), 'Registered legal entity, shown on invoices'],
            ['identity', 'display_name', config('veritas.identity.display_name'), 'Marketplace name shown to customers'],
            ['identity', 'support_email', config('veritas.identity.support_email'), 'Customer support address'],
            ['identity', 'billing_email', config('veritas.identity.billing_email'), 'Finance and billing address'],
            ['identity', 'business_address', config('veritas.identity.business_address'), 'Registered address'],
            ['identity', 'country', config('veritas.identity.country'), 'Operating country'],
            ['identity', 'public_url', config('veritas.identity.public_url'), 'Public storefront URL'],
            ['identity', 'sender_email', config('veritas.identity.sender_email'), 'Envelope sender for outbound mail'],
            ['identity', 'sender_name', config('veritas.identity.sender_name'), 'Display name on outbound mail'],
            ['identity', 'timezone', config('veritas.identity.timezone'), 'Platform reporting timezone'],

            ['branding', 'logo_path', config('veritas.branding.logo_path'), 'Marketplace logo on the media disk'],
            ['branding', 'favicon_path', config('veritas.branding.favicon_path'), 'Browser tab icon'],
            ['branding', 'email_logo_path', config('veritas.branding.email_logo_path'), 'Logo used in email headers'],
            ['branding', 'email_accent', config('veritas.branding.email_accent'), 'Accent colour in email templates'],

            ['money', 'default_currency', config('veritas.money.default_currency'), 'Single currency in Phase 1'],

            ['payouts', 'seller_clearing_period_days', config('veritas.payouts.seller_clearing_period_days'), 'Days a delivered earning clears before it is withdrawable'],
            ['payouts', 'minimum_minor', config('veritas.payouts.minimum_minor'), 'Minimum payout in minor units'],

            ['commission', 'default_rate_percent', config('veritas.commission.default_rate_percent'), 'Platform default commission'],
            ['commission', 'minimum_notice_days', config('veritas.commission.minimum_notice_days'), 'Notice sellers get before a rate change'],

            ['inventory', 'low_stock_threshold', config('veritas.inventory.low_stock_threshold'), 'Available quantity at or below which stock reads Low'],
            ['inventory', 'reservation_ttl_minutes', config('veritas.inventory.reservation_ttl_minutes'), 'How long a checkout holds stock'],
        ];

        foreach ($settings as [$group, $key, $value, $description]) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => "{$group}.{$key}"],
                [
                    'value' => json_encode($value),
                    'group' => $group,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
