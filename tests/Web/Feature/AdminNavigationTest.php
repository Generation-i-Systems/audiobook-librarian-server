<?php

declare(strict_types=1);

namespace Tests\Web\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function adminDropdownGroupsRequestedLinksAndKeepsRestrictedLinksForAdmins(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $html = $this->get(route('books.index'))->assertOk()->getContent();
        $this->assertIsString($html);
        preg_match('/<div class="dropdown-menu" aria-labelledby="adminDropdown">(.*?)<\/div>/s', $html, $matches);
        $this->assertNotEmpty($matches);
        foreach (['Skins', 'Themes', 'Badges', 'User Management', 'Database Admin', 'Queue'] as $label) {
            $this->assertStringContainsString('>' . $label . '</a>', $matches[1]);
        }
    }

    #[Test]
    public function nonAdminDropdownDoesNotExposeAdminOnlyLinks(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'librivox-user']));
        $html = $this->get(route('books.index'))->assertOk()->getContent();
        $this->assertIsString($html);
        preg_match('/<div class="dropdown-menu" aria-labelledby="adminDropdown">(.*?)<\/div>/s', $html, $matches);
        $this->assertNotEmpty($matches);
        foreach (['Skins', 'Themes', 'Badges'] as $label) {
            $this->assertStringContainsString('>' . $label . '</a>', $matches[1]);
        }
        foreach (['User Management', 'Database Admin', 'Queue'] as $label) {
            $this->assertStringNotContainsString('>' . $label . '</a>', $matches[1]);
        }
    }
}
