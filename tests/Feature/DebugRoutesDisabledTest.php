<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DebugRoutesDisabledTest extends TestCase
{
    public function testUnsafeDebugRoutesAreNotRegisteredByDefault(): void
    {
        $this->assertFalse((bool) config('app.enable_debug_routes'));

        $this->get('/reset-test-password')->assertNotFound();
        $this->get('/test-auth')->assertNotFound();
        $this->get('/debug/users-dump')->assertNotFound();
        $this->get('/debug/books-dump')->assertNotFound();
        $this->get('/debug/relationships')->assertNotFound();
    }
}
