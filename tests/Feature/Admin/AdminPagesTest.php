<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Role;
use App\Models\Page;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    public static function adminOnlyPages(): array
    {
        return [
            'accounts' => ['/admin/users'],
            'invitations' => ['/admin/invitations'],
            'applications' => ['/admin/staff-applications'],
            'audit log' => ['/admin/audit-events'],
            'settings' => ['/admin/settings'],
        ];
    }

    public static function contentPages(): array
    {
        return [
            'pages' => ['/admin/pages'],
            'posts' => ['/admin/posts'],
            'new post' => ['/admin/posts/create'],
            'media' => ['/admin/media'],
            'upload' => ['/admin/media/create'],
            'comments' => ['/admin/comments'],
            'categories' => ['/admin/categories'],
            'tags' => ['/admin/tags'],
            'redirects' => ['/admin/redirects'],
            'new redirect' => ['/admin/redirects/create'],
        ];
    }

    #[DataProvider('adminOnlyPages')]
    public function test_full_administrators_can_open_admin_pages(string $url): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::FirmPrincipal))->get($url)->assertOk();
    }

    #[DataProvider('adminOnlyPages')]
    public function test_other_staff_cannot_open_admin_pages(string $url): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::Lawyer, Role::ContentEditor))->get($url)->assertForbidden();
    }

    #[DataProvider('contentPages')]
    public function test_content_editors_can_open_content_pages(string $url): void
    {
        $this->seed(PageSeeder::class);
        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor))->get($url)->assertOk();
    }

    #[DataProvider('contentPages')]
    public function test_lawyers_cannot_open_content_pages(string $url): void
    {
        $this->actingAsStaff($this->userWithRoles(Role::Lawyer))->get($url)->assertForbidden();
    }

    public function test_every_seeded_page_opens_in_the_editor(): void
    {
        $this->seed(PageSeeder::class);
        $this->actingAsStaff($this->userWithRoles(Role::ContentEditor));

        foreach (Page::all() as $page) {
            $this->get("/admin/pages/{$page->id}/edit")->assertOk()->assertSee($page->title);
        }
    }

    public function test_panel_requires_the_staff_login_marker(): void
    {
        $this->actingAs($this->userWithRoles(Role::FirmPrincipal))->get('/admin/users')->assertRedirect('/admin/login');
    }
}
