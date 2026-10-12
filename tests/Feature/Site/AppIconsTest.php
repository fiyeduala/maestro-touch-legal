<?php

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Installing the site on a phone shows the firm's mark, not a generated letter. */
class AppIconsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_manifest_is_valid_and_its_icons_are_square_pngs(): void
    {
        foreach (['site' => '/', 'portal' => '/portal/', 'staff' => '/admin'] as $name => $start) {
            $manifest = json_decode(file_get_contents(public_path("{$name}.webmanifest")), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($start, $manifest['start_url']);
            $this->assertSame('standalone', $manifest['display']);

            foreach ($manifest['icons'] as $icon) {
                [$width, $height, $type] = getimagesize(public_path(ltrim($icon['src'], '/')));
                $this->assertSame($icon['sizes'], "{$width}x{$height}");
                $this->assertSame(IMAGETYPE_PNG, $type);
            }
        }
        [$w, $h] = getimagesize(public_path('icons/apple-touch-icon.png'));
        $this->assertSame([180, 180], [$w, $h]);
    }

    public function test_pages_link_the_right_manifest(): void
    {
        $this->get('/legal-assistance/')->assertOk()->assertSee('/site.webmanifest', false)->assertSee('/icons/apple-touch-icon.png', false);
        $this->get('/log-in/')->assertOk()->assertSee('/site.webmanifest', false);
        $this->get('/admin/login')->assertOk()->assertSee('/staff.webmanifest', false)->assertSee('/icons/apple-touch-icon.png', false);
    }
}
