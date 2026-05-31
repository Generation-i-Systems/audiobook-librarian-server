<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ControllerDatabaseBoundaryTest extends TestCase
{
    #[Test]
    public function controllersDoNotUseTheDatabaseFacadeDirectly(): void
    {
        $controllerFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'))
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $controllerFiles[] = $fileInfo->getPathname();
            }
        }

        $violations = [];
        foreach ($controllerFiles as $controllerFile) {
            $contents = file_get_contents($controllerFile);
            if ($contents === false) {
                continue;
            }

            if (
                str_contains($contents, 'Illuminate\Support\Facades\DB')
                || preg_match('/\bDB::/', $contents) === 1
            ) {
                $violations[] = str_replace(base_path() . '/', '', $controllerFile);
            }
        }

        sort($violations);

        $this->assertSame([], $violations);
    }
}
