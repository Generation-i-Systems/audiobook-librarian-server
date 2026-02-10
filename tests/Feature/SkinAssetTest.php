<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class SkinAssetTest extends TestCase
{
    use RefreshDatabase;

    protected $tempSkinPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for skins
        $this->tempSkinPath = storage_path('framework/testing/skins');
        if (!File::exists($this->tempSkinPath)) {
            File::makeDirectory($this->tempSkinPath, 0755, true);
        }

        // Configure the application to use this path
        Config::set('app.skin_paths', $this->tempSkinPath);
    }

    protected function tearDown(): void
    {
        // Cleanup
        if (File::exists($this->tempSkinPath)) {
            File::deleteDirectory($this->tempSkinPath);
        }

        parent::tearDown();
    }

    public function test_can_retrieve_asset_from_extracted_directory()
    {
        // Create a skin directory
        $skinId = 'test-skin-dir';
        $skinPath = $this->tempSkinPath . '/' . $skinId;
        File::makeDirectory($skinPath, 0755, true);

        // Create an asset file
        $assetContent = 'test asset content';
        $assetPath = 'assets/test.txt';
        File::makeDirectory(dirname($skinPath . '/' . $assetPath), 0755, true);
        File::put($skinPath . '/' . $assetPath, $assetContent);

        // Request the asset
        $response = $this->get("/skin-asset/{$skinId}/{$assetPath}");

        $response->assertStatus(200);
    }

    public function test_can_retrieve_asset_from_zip_file()
    {
        // Create a zip file
        $skinId = 'test-skin-zip';
        $zipPath = $this->tempSkinPath . '/' . $skinId . '.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $assetContent = 'test zip asset content';
        $assetPath = 'assets/test.txt';
        $zip->addFromString($assetPath, $assetContent);
        $zip->close();

        // Request the asset
        $response = $this->get("/skin-asset/{$skinId}/{$assetPath}");

        $response->assertStatus(200);
        $response->assertSee($assetContent);
    }

    public function test_returns_404_if_skin_not_found()
    {
        $response = $this->get('/skin-asset/non-existent-skin/assets/test.txt');

        $response->assertStatus(404);
    }

    public function test_returns_404_if_asset_not_found_in_skin()
    {
        // Create a skin directory
        $skinId = 'test-skin-empty';
        $skinPath = $this->tempSkinPath . '/' . $skinId;
        File::makeDirectory($skinPath, 0755, true);

        $response = $this->get("/skin-asset/{$skinId}/non-existent-asset.txt");

        $response->assertStatus(404);
    }
}
