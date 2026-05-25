<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ApiRootTest extends TestCase
{
    /** @test */
    public function it_exposes_environment_information_at_root()
    {
        // Mock the environment
        config(['app.env' => 'development']);
        config(['database.default' => 'mysql_devel']);

        $response = $this->getJson('/api/v1');

        $response->assertStatus(200);
        $response->assertJsonPath('name', 'Librarian API');
        $response->assertJsonPath('version', 'v1');
        $response->assertJsonPath('environment', 'development');
        $response->assertJsonPath('otp_available', false);
    }

    /** @test */
    public function it_identifies_production_environment()
    {
        // Mock production
        config(['app.env' => 'production']);
        config(['database.default' => 'mysql_production']);

        $response = $this->getJson('/api/v1');

        $response->assertStatus(200)
            ->assertJsonPath('is_devel_site', false);
    }
}
