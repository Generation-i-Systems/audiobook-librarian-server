<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AllViewsCompileTest extends TestCase
{
    public function testEveryBladeViewCompilesToValidPhp(): void
    {
        $broken = [];
        $tokenCount = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getFilename(), ' ')) {
                continue;
            }
            $compiled = Blade::compileString((string) file_get_contents($file->getPathname()));
            try {
                $tokenCount += count(token_get_all($compiled, TOKEN_PARSE));
            } catch (\ParseError $e) {
                $broken[] = $file->getPathname() . ': ' . $e->getMessage();
            }
        }

        $this->assertSame([], $broken);
        $this->assertGreaterThan(0, $tokenCount);
    }
}
