<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\BookListPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class BookListPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'username' => $name,
            'email' => $name . '@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'role' => 'user',
        ]);
    }

    public function testSetPreferenceEndpointPersistsPerUser(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');

        $posts = ['main_view_type' => 'list', 'main_per_page' => 48, 'main_sort' => 'author_desc'];
        foreach ($posts as $key => $value) {
            $this->actingAs($alice)->postJson(route('books.set-preference'), ['key' => $key, 'value' => $value])
                ->assertOk();
        }

        $this->assertSame(
            ['view_type' => 'list', 'per_page' => 48, 'sort' => 'author_desc'],
            $alice->fresh()->book_list_preferences
        );
        $this->assertNull($bob->fresh()->book_list_preferences);
    }

    public function testSavedPreferencesSurviveANewSession(): void
    {
        $user = $this->makeUser('carol');
        $service = new BookListPreferenceService();
        $request = Request::create('/books');
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => $user);
        $service->set($request, 'view_type', 'compact');

        $freshRequest = Request::create('/books');
        $freshRequest->setLaravelSession($this->app['session.store']);
        $freshRequest->session()->flush();
        $freshRequest->setUserResolver(fn () => $user->fresh());

        $this->assertSame('compact', $service->get($freshRequest, 'view_type', 'grid'));
    }

    public function testInvalidValuesAreRejectedAndNotStored(): void
    {
        $user = $this->makeUser('dave');

        $invalid = [['main_view_type', 'carousel'], ['main_per_page', 7], ['main_sort', 'bogus'], ['nope', 'x']];
        foreach ($invalid as [$key, $value]) {
            $this->actingAs($user)->postJson(route('books.set-preference'), ['key' => $key, 'value' => $value])
                ->assertStatus(422);
        }

        $this->assertNull($user->fresh()->book_list_preferences);
    }
}
