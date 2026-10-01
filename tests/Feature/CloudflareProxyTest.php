<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The visitor's address and https are taken from Cloudflare's headers only when Cloudflare sent the request. */
class CloudflareProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('test/client/address/', fn (Request $request) => ['ip' => $request->ip(), 'secure' => $request->isSecure()]);
    }

    public function test_requests_through_cloudflare_use_the_forwarded_address_and_scheme(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.70.1.2'])
            ->withHeaders(['X-Forwarded-For' => '41.58.10.20', 'X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/test/client/address/')
            ->assertExactJson(['ip' => '41.58.10.20', 'secure' => true]);
    }

    public function test_direct_requests_cannot_fake_the_address_or_scheme(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '41.58.10.20'])
            ->withHeaders(['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/test/client/address/')
            ->assertExactJson(['ip' => '41.58.10.20', 'secure' => false]);
    }
}
