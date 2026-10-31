<?php

namespace Tests\Feature\System;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class SystemPagesAndSeoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * All required HTTP error views exist and render user-friendly Persian UI without leaking traces.
     */
    public function test_all_fourteen_standard_error_pages_exist_and_render_securely(): void
    {
        $codes = [400, 401, 403, 404, 405, 408, 419, 422, 429, 500, 502, 503, 504, 505];

        foreach ($codes as $code) {
            $viewName = "errors.{$code}";
            $this->assertTrue(View::exists($viewName), "Error view {$viewName} does not exist.");

            $rendered = View::make($viewName)->render();

            $this->assertStringContainsString((string) $code, $rendered, "View {$viewName} does not display status code.");
            $this->assertStringContainsString('GetNumber', $rendered);
            $this->assertStringContainsString('noindex', $rendered);
            $this->assertStringNotContainsString('Stack trace', $rendered);
            $this->assertStringNotContainsString('/vendor/', $rendered);
            $this->assertStringNotContainsString('SQLSTATE', $rendered);
        }
    }

    /**
     * Non-existent web route returns standard 404 error page.
     */
    public function test_non_existent_page_renders_404_page(): void
    {
        $response = $this->get('/non-existent-page-xyz-404-test');

        $response->assertStatus(404);
        $response->assertSee('404');
        $response->assertSee('صفحه مورد نظر یافت نشد');
        $response->assertDontSee('Stack trace');
    }

    /**
     * Sitemap XML route returns valid XML content with public routes.
     */
    public function test_sitemap_xml_returns_valid_structure(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $response->assertSee('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false);
        $response->assertSee(url('/'), false);
        $response->assertSee(url('/docs/api'), false);
        $response->assertDontSee('/admin');
        $response->assertDontSee('/dashboard');
    }

    /**
     * Public page includes standard SEO, Open Graph, Twitter cards, and structured JSON-LD data.
     */
    public function test_public_welcome_page_has_complete_seo_tags(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('name="description"', false);
        $response->assertSee('rel="canonical"', false);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('property="og:description"', false);
        $response->assertSee('property="og:site_name"', false);
        $response->assertSee('name="twitter:card"', false);
        $response->assertSee('name="robots" content="index, follow', false);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('SoftwareApplication', false);
    }

    /**
     * Authenticated and private pages enforce noindex and nofollow in robots meta tag.
     */
    public function test_authenticated_dashboard_enforces_noindex_robots(): void
    {
        /** @var User $user */
        $user = User::factory()->create([
            'role' => \App\Enums\UserRole::User,
            'status' => \App\Enums\UserStatus::Active,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('name="robots" content="noindex, nofollow, noarchive, nosnippet"', false);
    }

    /**
     * Verify .env.example specifies APP_DEBUG=false and contains no live secrets.
     */
    public function test_env_example_has_app_debug_false_and_no_secrets(): void
    {
        $envExamplePath = base_path('.env.example');
        $this->assertFileExists($envExamplePath);

        $content = file_get_contents($envExamplePath);
        $this->assertStringContainsString('APP_DEBUG=false', $content);
        $this->assertStringNotContainsString('base64:', $content);
        $this->assertMatchesRegularExpression('/APP_KEY=\r?\n/', $content);
    }

    /**
     * Verify robots.txt protects private and administrative areas.
     */
    public function test_robots_txt_disallows_private_paths(): void
    {
        $robotsPath = public_path('robots.txt');
        $this->assertFileExists($robotsPath);

        $content = file_get_contents($robotsPath);
        $this->assertStringContainsString('Disallow: /admin', $content);
        $this->assertStringContainsString('Disallow: /dashboard', $content);
        $this->assertStringContainsString('Disallow: /numbers', $content);
        $this->assertStringContainsString('Disallow: /messages', $content);
        $this->assertStringContainsString('Disallow: /api-tokens', $content);
        $this->assertStringContainsString('Disallow: /tickets', $content);
        $this->assertStringContainsString('Sitemap:', $content);
    }
}
