<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;

class OpenApiRequiredPathsTest extends ApiTestCase
{
    #[Test]
    public function openApiSpecContainsRequiredPaths(): void
    {
        $path = base_path('docs/openapi.json');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $spec = json_decode($contents, true);
        $this->assertIsArray($spec);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertIsArray($spec['paths']);

        $requiredPaths = [
            '/auth/google',
            '/auth/logout',
            '/auth/refresh',
            '/genres',
            '/progress',
            '/progress/{book}',
            '/analytics/event',
            '/badges',
            '/badges/user',
            '/badges/stats',
            '/badges/categories',
            '/badges/progress',
            '/badges/unnotified',
            '/badges/mark-notified',
            '/badges/leaderboard',
            '/books/{book}/recommend',
            '/authors/{authorId}',
            '/series/{seriesId}',
            '/recommendations/{recommendation}/acknowledge',
        ];

        foreach ($requiredPaths as $requiredPath) {
            $this->assertArrayHasKey($requiredPath, $spec['paths']);
        }
    }
}
