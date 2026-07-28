<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PathTraversalTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;
    protected string $originalBookRoot;
    protected string $originalSkinPaths;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBookRoot = (string) config('app.book_root', '');
        $this->originalSkinPaths = (string) config('app.skin_paths', '');

        $this->tempDir = storage_path('framework/testing/path-traversal-' . uniqid());
        File::makeDirectory($this->tempDir, 0755, true);

        // /image-proxy and /cover/{path} now require authentication (they serve
        // arbitrary files from book_root, previously reachable by anyone).
        $this->user = User::factory()->create(['role' => 'library-user']);
    }

    protected function tearDown(): void
    {
        // Restore config so downstream tests see the original values.
        config([
            'app.book_root' => $this->originalBookRoot,
            'app.skin_paths' => $this->originalSkinPaths,
        ]);

        if (File::exists($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /** A real, minimal 1x1 PNG — libmagic must sniff it as image/png for the MIME check. */
    private function validPngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        );
    }

    private function withStorageRoot(): static
    {
        // Skip the middleware that overrides app.book_root from a library profile,
        // so that Config::set takes effect inside the controller.
        return $this->withoutMiddleware(\App\Http\Middleware\ResolveLibraryProfileFromHost::class)
            ->actingAs($this->user);
    }

    // ── ImageProxyController::show() ────────────────────────────────────────────

    public function testImageProxyShowBlocksPathTraversalViaFile(): void
    {
        Config::set('app.book_root', $this->tempDir);

        $response = $this->withStorageRoot()->get('/image-proxy?file=../../etc/passwd');

        $response->assertStatus(404);
    }

    public function testImageProxyShowBlocksEncodedTraversalViaFile(): void
    {
        Config::set('app.book_root', $this->tempDir);

        $response = $this->withStorageRoot()->get('/image-proxy?file=..%2F..%2Fetc%2Fpasswd');

        $response->assertStatus(404);
    }

    public function testImageProxyShowServesLegitimateFile(): void
    {
        Config::set('app.book_root', $this->tempDir);

        file_put_contents($this->tempDir . '/cover.png', $this->validPngBytes());

        $response = $this->withStorageRoot()->get('/image-proxy?file=cover.png');

        $response->assertStatus(200);
    }

    public function testImageProxyShowBlocksTraversalViaDir(): void
    {
        Config::set('app.book_root', $this->tempDir);

        // Parent of tempDir
        file_put_contents(dirname($this->tempDir) . '/sensitive.txt', 'secret');

        $response = $this->withStorageRoot()->get('/image-proxy?dir=../..&file=sensitive.txt');

        $response->assertStatus(404);
    }

    // ── ImageProxyController::cover() ───────────────────────────────────────────

    public function testCoverBlocksPathTraversal(): void
    {
        Config::set('app.book_root', $this->tempDir);

        $response = $this->withStorageRoot()->get('/cover/' . rawurlencode('../../etc/passwd'));

        $response->assertStatus(404);
    }

    public function testCoverBlocksMultiDotTraversal(): void
    {
        Config::set('app.book_root', $this->tempDir);

        $response = $this->withStorageRoot()->get('/cover/../../../etc/passwd');

        $response->assertStatus(404);
    }

    // ── Authentication / MIME restrictions ──────────────────────────────────────

    public function testCoverRejectsUnauthenticatedRequest(): void
    {
        Config::set('app.book_root', $this->tempDir);
        file_put_contents($this->tempDir . '/cover.png', $this->validPngBytes());

        $response = $this
            ->withoutMiddleware(\App\Http\Middleware\ResolveLibraryProfileFromHost::class)
            ->get('/cover/cover.png');

        $response->assertRedirect(route('login'));
    }

    public function testCoverRejectsNonImageFileForNonAdmin(): void
    {
        Config::set('app.book_root', $this->tempDir);
        file_put_contents($this->tempDir . '/chapter1.mp3', str_repeat("\x00", 32));

        $response = $this->withStorageRoot()->get('/cover/chapter1.mp3');

        $response->assertStatus(403);
    }

    public function testCoverAllowsNonImageFileForAdmin(): void
    {
        Config::set('app.book_root', $this->tempDir);
        file_put_contents($this->tempDir . '/chapter1.mp3', str_repeat("\x00", 32));
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $response = $this
            ->withoutMiddleware(\App\Http\Middleware\ResolveLibraryProfileFromHost::class)
            ->actingAs($admin)
            ->get('/cover/chapter1.mp3');

        $response->assertStatus(200);
    }

    // SkinAssetController::show() moved to audiobook-librarian-www as part of
    // the skin/theme extraction — its path-traversal regression coverage now
    // lives there (tests/Feature/SkinAssetTest.php), since /skin-asset/* here
    // is now just a redirect to the identical www route.
}
