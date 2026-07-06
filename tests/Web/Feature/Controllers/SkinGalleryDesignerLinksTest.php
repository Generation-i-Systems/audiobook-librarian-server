<?php

namespace Tests\Web\Feature\Controllers;

use App\Auth\DocumentstoreUser;
use App\Models\Skin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SkinGalleryDesignerLinksTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSkinOwner(User $user): void
    {
        $this->actingAs(new DocumentstoreUser([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'user',
        ]));
    }

    private function createSkin(User $owner, array $overrides = []): Skin
    {
        return Skin::create(array_merge([
            'name' => 'Test Skin',
            'author' => $owner->name,
            'version' => '1.0',
            'user_id' => $owner->id,
            'file_path' => 'skins/test-skin.zip',
            'file_size' => 1024,
            'is_public' => true,
        ], $overrides));
    }

    #[Test]
    public function myOwnSkinsPageRendersInsteadOf500(): void
    {
        $owner = User::factory()->create();
        $this->actingAsSkinOwner($owner);
        $this->createSkin($owner);

        $response = $this->get(route('gallery.skins.my-skins'));

        $response->assertOk();
        $response->assertSee('My Skins');
        $response->assertSee(route('gallery.skins.designerNew'));
    }

    #[Test]
    public function myOwnSkinsPageLinksEachSkinToTheDesigner(): void
    {
        $owner = User::factory()->create();
        $this->actingAsSkinOwner($owner);
        $skin = $this->createSkin($owner);

        $response = $this->get(route('gallery.skins.my-skins'));

        $response->assertOk();
        $response->assertSee(route('gallery.skins.designer', $skin->id), false);
    }

    #[Test]
    public function galleryIndexShowsDesignNewSkinButtonForAuthenticatedUsers(): void
    {
        $owner = User::factory()->create();
        $this->actingAsSkinOwner($owner);

        $response = $this->get(route('gallery.skins.index'));

        $response->assertOk();
        $response->assertSee(route('gallery.skins.designerNew'));
    }

    #[Test]
    public function galleryIndexLinksOwnedSkinCardToTheDesignerButNotOthersSkins(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAsSkinOwner($owner);

        $ownSkin = $this->createSkin($owner, ['name' => 'My Own Skin']);
        $othersSkin = $this->createSkin($otherUser, ['name' => "Someone Else's Skin"]);

        $response = $this->get(route('gallery.skins.index'));

        $response->assertOk();
        $response->assertSee(route('gallery.skins.designer', $ownSkin->id), false);
        $response->assertDontSee(route('gallery.skins.designer', $othersSkin->id), false);
    }

    #[Test]
    public function adminSkinsIndexLinksEachSkinToTheDesigner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsSkinOwner($admin);
        $skin = $this->createSkin($admin);

        $response = $this->get(route('admin.skins.index'));

        $response->assertOk();
        $response->assertSee(route('gallery.skins.designer', $skin->id), false);
    }

    #[Test]
    public function adminSkinShowLinksToTheDesigner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsSkinOwner($admin);
        $skin = $this->createSkin($admin);

        $response = $this->get(route('admin.skins.show', $skin->id));

        $response->assertOk();
        $response->assertSee(route('gallery.skins.designer', $skin->id), false);
    }
}
