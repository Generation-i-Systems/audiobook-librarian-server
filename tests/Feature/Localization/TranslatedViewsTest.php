<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Tests\TestCase;

class TranslatedViewsTest extends TestCase
{
    public function testLoginPageRendersTranslatedStringsForSelectedLocale(): void
    {
        $locale = 'xx';
        $lines = [
            'Login' => 'LOGIN-' . $locale,
            'Forgot Your Password?' => 'FORGOT-' . $locale,
            'Sign in with Google' => 'GOOGLE-' . $locale,
        ];
        config(['locales.supported' => ['en' => 'English', $locale => 'Pseudo']]);
        $dir = sys_get_temp_dir() . '/lang-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/' . $locale . '.json', json_encode($lines));
        app('translator')->addJsonPath($dir);

        $response = $this->withHeader('Accept-Language', $locale)->get('/login');

        $response->assertOk();
        foreach ($lines as $translated) {
            $response->assertSee($translated, false);
        }

        unlink($dir . '/' . $locale . '.json');
        rmdir($dir);
    }

    public function testLoginPageFallsBackToEnglishKeysWhenNoTranslationExists(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Forgot Your Password?', false);
    }

    public function testLoginPageOffersTheLanguageSwitcherAndRendersSpanish(): void
    {
        $response = $this->withHeader('Accept-Language', 'es')->get('/login');

        $response->assertOk();
        $response->assertSee('<option value="es" selected>Español</option>', false);
        $response->assertSee('¿Olvidó su contraseña?', false);
        $response->assertSee('Iniciar sesión', false);
    }

    public function testLoginViewWithEmailCodeSignInRendersTranslatedButtons(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'mail.test']);
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('Send sign-in code', $html);
        $this->assertStringContainsString("window.__('Verify code')", $html);
    }
}
