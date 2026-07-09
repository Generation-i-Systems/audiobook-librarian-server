<?php

namespace Tests\Feature;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBookRoot = (string) config('app.book_root', '');
        $this->originalSkinPaths = (string) config('app.skin_paths', '');

        $this->tempDir = storage_path('framework/testing/path-traversal-' . uniqid());
        File::makeDirectory($this->tempDir, 0755, true);
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

    private function withStorageRoot(): static
    {
        // Skip the middleware that overrides app.book_root from a library profile,
        // so that Config::set takes effect inside the controller.
        return $this->withoutMiddleware(\App\Http\Middleware\ResolveLibraryProfileFromHost::class);
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

        file_put_contents($this->tempDir . '/cover.png', "\x89PNG\r\n\x1a\n");

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

    // SkinAssetController::show() moved to audiobook-librarian-www as part of
    // the skin/theme extraction — its path-traversal regression coverage now
    // lives there (tests/Feature/SkinAssetTest.php), since /skin-asset/* here
    // is now just a redirect to the identical www route.
}
