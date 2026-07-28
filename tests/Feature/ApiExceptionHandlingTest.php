<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiExceptionHandlingTest extends TestCase
{
    #[Test]
    public function testUnexpectedApiExceptionReturnsGenericMessageWithCorrelationId(): void
    {
        Route::get('/api/v1/__test-unexpected-exception', function () {
            throw new \RuntimeException('DB connection failed: password=super-secret@10.0.0.5');
        });

        $response = $this->getJson('/api/v1/__test-unexpected-exception');

        $response->assertStatus(500);
        $response->assertJson(['error' => true, 'message' => 'An unexpected error occurred.']);
        $response->assertJsonStructure(['correlation_id']);
        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $this->assertStringNotContainsString('DB connection failed', $response->getContent());
    }

    #[Test]
    public function testDeliberateHttpExceptionStillReturnsItsOwnMessage(): void
    {
        Route::get('/api/v1/__test-deliberate-abort', function () {
            abort(403, 'Forbidden');
        });

        $response = $this->getJson('/api/v1/__test-deliberate-abort');

        $response->assertStatus(403);
        $response->assertJson(['error' => true, 'message' => 'Forbidden']);
    }
}
