<?php

declare(strict_types=1);

namespace Tests\Web\Feature;

use App\Auth\DocumentstoreUser;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class BookShowTagsViewTest extends TestCase
{
    public function testAuthenticatedDocumentstoreUserCanRenderBookTags(): void
    {
        Auth::setUser(new DocumentstoreUser([
            'id' => '1',
            'name' => 'Admin',
            'role' => 'admin',
        ]));

        $html = view('books.show', [
            'book' => [
                'id' => '5809',
                'title' => 'Book',
                'authors' => [],
                'genres' => [],
            ],
            'relatedBooks' => [],
            'tags' => ['system' => [], 'groups' => [], 'user' => []],
            'popularTags' => collect(),
            'userGroups' => collect(),
        ])->render();

        $this->assertStringContainsString('No tags yet.', $html);
    }
}
