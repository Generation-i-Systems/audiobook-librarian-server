<?php

namespace Tests\Unit\Controllers\Api;

use App\Http\Controllers\Api\SkinController;
use App\Services\GalleryProxyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SkinControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_streams_the_zip_returned_by_www(): void
    {
        config(['services.gallery_www.base_url' => 'http://gallery-www.test']);

        Http::fake([
            'gallery-www.test/api/v1/skins/51/download' => Http::response(
                'binary zip content',
                200,
                [
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="Test Skin.zip"',
                ]
            ),
        ]);

        $controller = new SkinController(app(GalleryProxyClient::class));
        $response = $controller->download(51);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('binary zip content', $response->getContent());
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Test Skin.zip', $response->headers->get('Content-Disposition', ''));
    }

    public function test_download_passes_through_a_404_from_www(): void
    {
        config(['services.gallery_www.base_url' => 'http://gallery-www.test']);

        Http::fake([
            'gallery-www.test/api/v1/skins/404/download' => Http::response(['error' => 'Skin not found'], 404),
        ]);

        $controller = new SkinController(app(GalleryProxyClient::class));
        $response = $controller->download(404);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_show_forwards_wwws_json_body_and_status(): void
    {
        config(['services.gallery_www.base_url' => 'http://gallery-www.test']);

        Http::fake([
            'gallery-www.test/api/v1/skins/7' => Http::response(['id' => 7, 'name' => 'Test Skin'], 200),
        ]);

        $controller = new SkinController(app(GalleryProxyClient::class));
        $response = $controller->show(7);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['id' => 7, 'name' => 'Test Skin'], $response->getData(true));
    }
}
