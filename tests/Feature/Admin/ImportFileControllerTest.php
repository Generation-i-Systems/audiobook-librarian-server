<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ImportFileControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function roots_endpoint_returns_configured_roots()
    {
        Config::set('import.roots', [base_path('storage/import'), '/tmp']);
        $response = $this->get('/admin/import/roots');
        $response->assertStatus(200);
        $response->assertJsonFragment(['value' => base_path('storage/import')]);
        $response->assertJsonFragment(['value' => '/tmp']);
    }

    /** @test */
    public function list_endpoint_lists_files_and_dirs()
    {
        $root = base_path('storage/import');
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }
        file_put_contents($root . '/test.mp3', 'audio');
        mkdir($root . '/subdir');
        $response = $this->get('/admin/import/list?root=' . urlencode($root));
        $response->assertStatus(200);
        $json = $response->json();
        $this->assertTrue(
            collect($json['items'])->contains(fn ($item) => $item['type'] === 'file' && $item['name'] === 'test.mp3')
        );
        $this->assertTrue(
            collect($json['items'])->contains(fn ($item) => $item['type'] === 'dir' && $item['name'] === 'subdir')
        );
    }
}
