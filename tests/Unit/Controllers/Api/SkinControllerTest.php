<?php

namespace Tests\Unit\Controllers\Api;

use App\Http\Controllers\Api\SkinController;
use App\Models\Skin;
use App\Models\User;
use App\Services\Contracts\SkinServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class SkinControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_download_accepts_camel_case_file_path_from_service(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('skins/51/skin.zip', 'test skin data');
        $user = User::factory()->create();

        $skin = Skin::query()->create([
            'name' => 'Test Skin',
            'author' => 'Tester',
            'version' => '1.0',
            'description' => null,
            'user_id' => $user->id,
            'file_path' => 'skins/51/skin.zip',
            'preview_path' => null,
            'file_size' => 14,
            'manifest' => [],
            'is_public' => true,
            'download_count' => 0,
            'average_rating' => 0,
            'rating_count' => 0,
        ]);

        $skinService = Mockery::mock(SkinServiceInterface::class);
        $skinService->shouldReceive('getSkin')
            ->once()
            ->with($skin->id)
            ->andReturn([
                'id' => $skin->id,
                'name' => 'Test Skin',
                'filePath' => 'skins/51/skin.zip',
            ]);

        $controller = new SkinController($skinService);
        $response = $controller->download($skin->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment; filename="Test Skin.zip"', $response->headers->get('content-disposition', ''));
        $this->assertSame(1, Skin::query()->findOrFail($skin->id)->download_count);
    }

    public function test_download_builds_zip_when_stored_file_is_missing(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $skin = Skin::query()->create([
            'name' => 'Generated Skin',
            'author' => 'Tester',
            'version' => '1.0',
            'description' => null,
            'user_id' => $user->id,
            'file_path' => 'skins/999/skin.zip',
            'preview_path' => null,
            'file_size' => 14,
            'manifest' => [],
            'is_public' => true,
            'download_count' => 0,
            'average_rating' => 0,
            'rating_count' => 0,
        ]);

        $generatedZipPath = storage_path("app/skins/{$skin->id}/skin_generated_test.zip");
        if (! is_dir(dirname($generatedZipPath))) {
            mkdir(dirname($generatedZipPath), 0755, true);
        }
        file_put_contents($generatedZipPath, 'generated zip');

        $skinService = Mockery::mock(SkinServiceInterface::class);
        $skinService->shouldReceive('getSkin')
            ->once()
            ->with($skin->id)
            ->andReturn([
                'id' => $skin->id,
                'name' => 'Generated Skin',
                'filePath' => 'skins/999/skin.zip',
            ]);
        $skinService->shouldReceive('buildZip')
            ->once()
            ->with($skin->id)
            ->andReturn($generatedZipPath);

        $controller = new SkinController($skinService);
        $response = $controller->download($skin->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment; filename="Generated Skin.zip"', $response->headers->get('content-disposition', ''));
        $this->assertSame(1, Skin::query()->findOrFail($skin->id)->download_count);

        @unlink($generatedZipPath);
    }

    public function test_download_uses_legacy_storage_path_before_rebuilding(): void
    {
        $user = User::factory()->create();

        $skin = Skin::query()->create([
            'name' => 'Legacy Skin',
            'author' => 'Tester',
            'version' => '1.0',
            'description' => null,
            'user_id' => $user->id,
            'file_path' => 'skins/51/skin.zip',
            'preview_path' => null,
            'file_size' => 14,
            'manifest' => [],
            'is_public' => true,
            'download_count' => 0,
            'average_rating' => 0,
            'rating_count' => 0,
        ]);

        $legacyZipPath = storage_path('app/skins/51/skin.zip');
        if (! is_dir(dirname($legacyZipPath))) {
            mkdir(dirname($legacyZipPath), 0755, true);
        }
        $zip = new ZipArchive();
        $zip->open($legacyZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('nifest.json', '{}');
        $zip->addFromString('sets/images/background.png', 'image');
        $zip->addFromString('eview.png', 'preview');
        $zip->close();

        $skinService = Mockery::mock(SkinServiceInterface::class);
        $skinService->shouldReceive('getSkin')
            ->once()
            ->with($skin->id)
            ->andReturn([
                'id' => $skin->id,
                'name' => 'Legacy Skin',
                'filePath' => 'skins/51/skin.zip',
            ]);
        $skinService->shouldNotReceive('buildZip');

        $controller = new SkinController($skinService);
        $response = $controller->download($skin->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment; filename="Legacy Skin.zip"', $response->headers->get('content-disposition', ''));

        $servedZip = new ZipArchive();
        $servedZip->open($response->getFile()->getPathname());
        $this->assertNotFalse($servedZip->locateName('manifest.json'));
        $this->assertNotFalse($servedZip->locateName('assets/images/background.png'));
        $this->assertNotFalse($servedZip->locateName('preview.png'));
        $servedZip->close();

        @unlink($legacyZipPath);
    }
}
