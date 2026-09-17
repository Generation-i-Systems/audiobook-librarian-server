<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Controllers\Api\BookDownloadController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BookAudioPlaybackRouteTest extends TestCase
{
    public function testAudioPlaybackRouteUsesTheBookDownloadControllerAndManageBooksPermission(): void
    {
        $route = Route::getRoutes()->getByName('admin.books.playAudio');

        $this->assertNotNull($route);
        $this->assertSame('admin/books/{book}/play/{file}', $route->uri());
        $this->assertSame(BookDownloadController::class . '@downloadFile', $route->getActionName());
        $this->assertContains('auth', $route->middleware());
        $this->assertContains('permission:manage-books', $route->middleware());
    }
}
