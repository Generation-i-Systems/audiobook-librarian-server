<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Tests\TestCase;

class ResolveLibraryProfileFromHostTest extends TestCase
{
    public function test_it_applies_librivox_profile_for_matching_host(): void
    {
        $this->configureProfiles();

        $response = $this->getJson('http://librivox.example.test/api/v1');

        $response->assertStatus(200);
        $response->assertJsonPath('database', 'testing');

        $this->assertSame('librivox', config('library_profiles.active_profile'));
        $this->assertSame('librivox', config('library_profiles.active_source_mode'));
        $this->assertSame('/tmp/librivox-books', config('filesystems.disks.books.root'));
    }

    public function test_it_falls_back_to_default_profile_for_unknown_host(): void
    {
        $this->configureProfiles();

        $response = $this
            ->withHeaders(['Host' => 'unknown.example.test'])
            ->getJson('/api/v1');

        $response->assertStatus(200);
        $response->assertJsonPath('database', 'sqlite');

        $this->assertSame('main', config('library_profiles.active_profile'));
        $this->assertSame('local', config('library_profiles.active_source_mode'));
        $this->assertSame('/tmp/main-books', config('filesystems.disks.books.root'));
    }

    public function test_it_uses_forwarded_host_header_when_present(): void
    {
        $this->configureProfiles();

        $response = $this
            ->withHeaders(['Host' => 'proxy.internal'])
            ->withHeaders(['X-Forwarded-Host' => 'librivox.example.test,main.example.test'])
            ->getJson('/api/v1');

        $response->assertStatus(200);
        $response->assertJsonPath('database', 'testing');

        $this->assertSame('librivox', config('library_profiles.active_profile'));
    }

    private function configureProfiles(): void
    {
        config([
            'library_profiles.default_profile' => 'main',
            'library_profiles.fallback_to_default_on_unknown_host' => true,
            'library_profiles.profiles' => [
                'main' => [
                    'hosts' => ['main.example.test'],
                    'database_connection' => 'sqlite',
                    'book_storage_path' => '/tmp/main-books',
                    'source_mode' => 'local',
                ],
                'librivox' => [
                    'hosts' => ['librivox.example.test'],
                    'database_connection' => 'testing',
                    'book_storage_path' => '/tmp/librivox-books',
                    'source_mode' => 'librivox',
                ],
            ],
        ]);
    }
}
