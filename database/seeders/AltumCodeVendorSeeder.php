<?php

declare(strict_types=1);

namespace Core\Mod\Uptelligence\Database\Seeders;

use Core\Mod\Uptelligence\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Seeds the uptelligence_vendors table with AltumCode products and plugins.
 *
 * Idempotent: uses updateOrCreate so it can be run multiple times safely.
 */
class AltumCodeVendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->products() as $product) {
            Vendor::updateOrCreate(
                ['slug' => $product['slug']],
                $product,
            );
        }

        foreach ($this->plugins() as $plugin) {
            Vendor::updateOrCreate(
                ['slug' => $plugin['slug']],
                $plugin,
            );
        }
    }

    /**
     * AltumCode licensed products.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function products(): array
    {
        return [
            [
                'slug' => '66analytics',
                'name' => '66analytics',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_LICENSED,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => '66biolinks',
                'name' => '66biolinks',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_LICENSED,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => '66pusher',
                'name' => '66pusher',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_LICENSED,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => '66socialproof',
                'name' => '66socialproof',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_LICENSED,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
        ];
    }

    /**
     * AltumCode plugins.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function plugins(): array
    {
        return [
            [
                'slug' => 'altum-plugin-affiliate',
                'name' => 'Affiliate Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-push-notifications',
                'name' => 'Push Notifications Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-teams',
                'name' => 'Teams Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-pwa',
                'name' => 'PWA Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-image-optimizer',
                'name' => 'Image Optimizer Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-email-shield',
                'name' => 'Email Shield Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-dynamic-og-images',
                'name' => 'Dynamic OG Images Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-offload',
                'name' => 'Offload & CDN Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-payment-blocks',
                'name' => 'Payment Blocks Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-ultimate-blocks',
                'name' => 'Ultimate Blocks Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-pro-blocks',
                'name' => 'Pro Blocks Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-pro-notifications',
                'name' => 'Pro Notifications Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
            [
                'slug' => 'altum-plugin-aix',
                'name' => 'AIX Plugin',
                'vendor_name' => 'AltumCode',
                'source_type' => Vendor::SOURCE_PLUGIN,
                'plugin_platform' => Vendor::PLATFORM_ALTUM,
                'current_version' => '0.0.0',
                'is_active' => true,
            ],
        ];
    }
}
