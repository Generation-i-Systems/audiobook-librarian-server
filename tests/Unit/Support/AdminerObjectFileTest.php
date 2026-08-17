<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminerObjectFileTest extends TestCase
{
    #[Test]
    public function adminerObjectReturnsTheCustomAdminerWithTheGeminiPlugin(): void
    {
        $this->assertFalse(function_exists('adminer_object'));

        eval('namespace Adminer { class Adminer {} abstract class Plugin {} }');

        require_once base_path('app/Support/adminer_object.php');

        $customAdminerClass = self::customAdminerClass();

        $this->assertTrue(function_exists('adminer_object'));
        $this->assertSame($customAdminerClass, get_class(adminer_object()));

        $plugin = new class () {
            public bool $headersCalled = false;

            public bool $sqlPrintAfterCalled = false;

            public function headers(): void
            {
                $this->headersCalled = true;
            }

            public function sqlPrintAfter(): void
            {
                $this->sqlPrintAfterCalled = true;
            }
        };
        $adminer = new $customAdminerClass($plugin);

        call_user_func([$adminer, 'headers']);
        call_user_func([$adminer, 'sqlPrintAfter']);

        $this->assertTrue($plugin->headersCalled);
        $this->assertTrue($plugin->sqlPrintAfterCalled);
    }

    private static function customAdminerClass(): string
    {
        return implode('\\', ['Adminer', 'AdminerCustom']);
    }
}
