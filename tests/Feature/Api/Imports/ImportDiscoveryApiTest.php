<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Enums\PermissionKey;
use App\Models\Imports\ImportDraft;
use App\Models\Permission;
use App\Models\User;
use Tests\Feature\Api\ApiTestCase;

class ImportDiscoveryApiTest extends ApiTestCase
{
    private const URL = '/api/v1/imports/discoveries';

    private const BIG = 12 * 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();
        config(['import_drafts.enabled' => true]);
        $this->grant($this->user);
    }

    private function grant(User $user): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => PermissionKey::IMPORT_BOOKS->value],
            ['label' => PermissionKey::IMPORT_BOOKS->label()]
        );
        $user->permissions()->syncWithoutDetaching([$permission->id]);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'mode' => 'paths',
            'selections' => ['16 - USS Crusader-Mark Wayne McGinnis'],
            'entries' => [
                ['path' => '16 - USS Crusader-Mark Wayne McGinnis', 'kind' => 'dir'],
                ['path' => '16 - USS Crusader-Mark Wayne McGinnis/USS_Crusader.m4b', 'kind' => 'file', 'bytes' => self::BIG],
                ['path' => '16 - USS Crusader-Mark Wayne McGinnis/folder.jpg', 'kind' => 'file', 'bytes' => 40000],
            ],
        ];
    }

    public function testAFolderWithOneM4bIsOneBookNamedByItsFolder(): void
    {
        $response = $this->postJson(self::URL, $this->request());

        $response->assertOk()
            ->assertJsonPath('contract_version', 'imports.v1')
            ->assertJsonPath('books.0.name', '16 - USS Crusader-Mark Wayne McGinnis')
            ->assertJsonPath('books.0.kind', 'book')
            ->assertJsonPath('books.0.files.0.path', '16 - USS Crusader-Mark Wayne McGinnis/USS_Crusader.m4b')
            ->assertJsonCount(1, 'books');
    }

    public function testItStoresNothing(): void
    {
        $before = ImportDraft::query()->count();

        $this->postJson(self::URL, $this->request())->assertOk();

        $this->assertSame($before, ImportDraft::query()->count());
    }

    public function testCapabilitiesAdvertiseDiscovery(): void
    {
        $this->getJson('/api/v1/imports/capabilities')->assertOk()->assertJsonPath('imports.book_discovery', true);
    }

    public function testItNeedsTheImportPermission(): void
    {
        $this->user->permissions()->detach();

        $this->postJson(self::URL, $this->request())->assertForbidden();
    }

    public function testItIsOffWhenDraftsAreDisabled(): void
    {
        config(['import_drafts.enabled' => false]);

        $this->assertContains($this->postJson(self::URL, $this->request())->status(), [403, 404]);
    }

    public function testPathsThatEscapeTheChosenFolderAreRejected(): void
    {
        $request = $this->request();
        $request['entries'][1]['path'] = '../private/book.m4b';

        $this->postJson(self::URL, $request)->assertUnprocessable();
    }

    public function testAbsolutePathsAreRejected(): void
    {
        $request = $this->request();
        $request['selections'] = ['/media/download/book'];

        $this->postJson(self::URL, $request)->assertUnprocessable();
    }

    public function testTheListingIsBounded(): void
    {
        config(['import_drafts.discovery.max_entries' => 2]);

        $this->postJson(self::URL, $this->request())->assertUnprocessable();
    }

    public function testAnUnknownModeIsRejected(): void
    {
        $request = $this->request();
        $request['mode'] = 'everything';

        $this->postJson(self::URL, $request)->assertUnprocessable();
    }

    public function testItRequiresSignIn(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => ''])->postJson(self::URL, $this->request())->assertUnauthorized();
    }
}
