<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiRouteOrderingTest extends TestCase
{
    public function testStaticBookRoutesAreNotCapturedByBookShowRoute(): void
    {
        $this->assertRouteUses('/api/v1/books/search', 'GET', 'App\Http\Controllers\Api\BookApiController@search');
        $this->assertRouteUses('/api/v1/books/browse', 'GET', 'App\Http\Controllers\Api\BookApiController@browse');
    }

    public function testSkinAndThemeAccountRoutesAreNotCapturedByPublicShowRoutes(): void
    {
        $this->assertRouteUses('/api/v1/skins/my-skins', 'GET', 'App\Http\Controllers\Api\SkinController@mySkins');
        $this->assertRouteUses('/api/v1/themes/my-themes', 'GET', 'App\Http\Controllers\Api\ThemeController@myThemes');
    }

    private function assertRouteUses(string $uri, string $method, string $expectedAction): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $this->assertSame($expectedAction, $route->getActionName());
    }
}
