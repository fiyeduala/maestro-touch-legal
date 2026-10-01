<?php

namespace App\Support;

/**
 * The site is served through Cloudflare (DECISIONS D44). Requests then arrive from Cloudflare's addresses, so the
 * visitor's address and the https scheme are read from the forwarded headers, but only when the request really
 * comes from one of these ranges. A visitor connecting directly cannot fake their address with the same headers.
 *
 * Source: https://www.cloudflare.com/ips/ (checked 1 October 2026). Cloudflare announces changes in advance;
 * compare this list with that page at each routine update (docs/UPGRADE-PATH.md).
 */
class Cloudflare
{
    public const PROXIES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}
