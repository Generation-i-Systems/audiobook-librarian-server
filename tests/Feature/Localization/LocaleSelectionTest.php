<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LocaleSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['locales.supported' => ['en' => 'English', 'es' => 'Español', 'pt-BR' => 'Português (Brasil)']]);
        config(['locales.default' => 'en']);
        Route::middleware('web')->get('/_locale-probe', fn () => response()->json(['locale' => app()->getLocale()]));
        Route::middleware('api')->get('/api/_locale-probe', fn () => response()->json(['locale' => app()->getLocale()]));
    }

    public function testDefaultsToTheConfiguredLocale(): void
    {
        $this->getJson('/_locale-probe')->assertExactJson(['locale' => 'en']);
    }

    public function testUsesTheBestSupportedAcceptLanguage(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr;q=0.9, es;q=0.8, en;q=0.1'])
            ->getJson('/_locale-probe')->assertExactJson(['locale' => 'es']);
    }

    public function testAcceptLanguageRegionVariantsMatchTheSupportedLocale(): void
    {
        $this->withHeaders(['Accept-Language' => 'pt-BR,pt;q=0.9'])
            ->getJson('/_locale-probe')->assertExactJson(['locale' => 'pt-BR']);
    }

    public function testUnsupportedAcceptLanguageFallsBackToTheDefault(): void
    {
        $this->withHeaders(['Accept-Language' => 'de, ja;q=0.8'])
            ->getJson('/_locale-probe')->assertExactJson(['locale' => 'en']);
    }

    public function testSessionLocaleBeatsAcceptLanguage(): void
    {
        $this->withSession(['locale' => 'es'])->withHeaders(['Accept-Language' => 'pt-BR'])
            ->getJson('/_locale-probe')->assertExactJson(['locale' => 'es']);
    }

    public function testStoredUserLocaleBeatsSessionAndAcceptLanguage(): void
    {
        $user = User::factory()->create(['locale' => 'pt-BR']);

        $this->actingAs($user)->withSession(['locale' => 'es'])->withHeaders(['Accept-Language' => 'es'])
            ->getJson('/_locale-probe')->assertExactJson(['locale' => 'pt-BR']);
    }

    public function testAnUnsupportedStoredLocaleIsIgnored(): void
    {
        $user = User::factory()->create(['locale' => 'xx']);

        $this->actingAs($user)->getJson('/_locale-probe')->assertExactJson(['locale' => 'en']);
    }

    public function testApiRequestsHonourAcceptLanguage(): void
    {
        $this->withHeaders(['Accept-Language' => 'es'])->getJson('/api/_locale-probe')
            ->assertExactJson(['locale' => 'es']);
    }

    public function testSwitchingLocaleStoresItInTheSession(): void
    {
        $this->post('/locale', ['locale' => 'es'])->assertRedirect();

        $this->assertSame('es', session('locale'));
    }

    public function testSwitchingLocaleAlsoSavesItOnTheSignedInUser(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)->post('/locale', ['locale' => 'pt-BR'])->assertRedirect();

        $this->assertSame('pt-BR', $user->fresh()->locale);
    }

    public function testSwitchingToAnUnsupportedLocaleIsRejected(): void
    {
        $this->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');

        $this->assertNull(session('locale'));
    }
}
