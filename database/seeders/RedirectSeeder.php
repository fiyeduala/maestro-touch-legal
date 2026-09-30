<?php

namespace Database\Seeders;

use App\Models\Redirect;
use Illuminate\Database\Seeder;

/**
 * Redirects for WordPress URLs that have no equivalent page in the new site (from the live sitemap, captured 28 Sep 2026).
 * Existing rows are left alone so admin edits to a redirect survive a re-seed.
 */
class RedirectSeeder extends Seeder
{
    public function run(): void
    {
        $portal = '/portal/';

        $map = [
            // Old WP Customer Area / WooCommerce account pages → client portal (login required there).
            '/customer-area/' => $portal,
            '/customer-area/dashboard/' => $portal,
            '/customer-area/files/' => $portal,
            '/customer-area/files/my-files/' => $portal,
            '/customer-area/pages/' => $portal,
            '/customer-area/pages/my-pages/' => $portal,
            '/customer-area/payments/' => $portal,
            '/customer-area/payments/checkout/' => $portal,
            '/customer-area/payments/payment-accepted/' => $portal,
            '/customer-area/payments/payment-rejected/' => $portal,
            '/customer-area/my-account/' => '/portal/profile',
            '/customer-area/my-account/account-details/' => '/portal/profile',
            '/customer-area/my-account/edit-account/' => '/portal/profile',
            '/customer-area/my-account/logout/' => '/log-in/',
            '/my-account/' => $portal,
            '/account/' => $portal,
            '/profile/' => '/portal/profile',
            '/payments-invoice/' => $portal,
            '/cart/' => $portal,
            '/checkout/' => $portal,
            '/shop/' => '/offering/',
            '/wp-2fa-config/' => '/admin/login',
            // WordPress entry points.
            '/wp-login.php' => '/log-in/',
            '/wp-admin/' => '/admin',
            '/comments/feed/' => '/feed/',
        ];

        foreach ($map as $from => $to) {
            Redirect::firstOrCreate(['from_path' => $from], [
                'to_path' => $to,
                'status_code' => 301,
                'source' => 'system',
            ]);
        }
    }
}
