<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    protected static bool $chromedriverAvailable = false;

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (!static::runningInSail()) {
            $binary = dirname(__DIR__) . '/vendor/laravel/dusk/bin/chromedriver-linux';
            if (!file_exists($binary)) {
                return;
            }
            static::$chromedriverAvailable = true;
            static::startChromeDriver(['--port=9515']);
        }
    }

    protected function setUp(): void
    {
        if (!static::runningInSail() && !static::$chromedriverAvailable) {
            $this->markTestSkipped('Chromedriver binary not found. Run: php artisan dusk:chrome-driver');
        }
        parent::setUp();
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $userDataDir = $_ENV['DUSK_USER_DATA_DIR']
            ?? env('DUSK_USER_DATA_DIR')
            ?? (sys_get_temp_dir() . '/librarian-dusk-profile-' . (string) getmypid());

        $options = new ChromeOptions();

        $browserPath = $_ENV['DUSK_BROWSER_PATH'] ?? env('DUSK_BROWSER_PATH');
        if (is_string($browserPath) && $browserPath !== '') {
            $options->setBinary($browserPath);
        }

        $options->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-extensions',
            '--user-data-dir=' . $userDataDir,
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY,
                $options
            )
        );
    }
}
