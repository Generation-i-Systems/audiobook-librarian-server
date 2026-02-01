<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminerObjectFileTest extends TestCase
{
    #[Test]
    public function adminerObjectFunctionIsDefinedAfterIncludingFile(): void
    {
        $this->assertFalse(function_exists('adminer_object'));

        require_once base_path('app/Support/adminer_object.php');

        $this->assertTrue(function_exists('adminer_object'));
    }
}
