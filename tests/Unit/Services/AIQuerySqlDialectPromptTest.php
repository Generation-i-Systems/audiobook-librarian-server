<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AIQueryService;
use Tests\TestCase;

class AIQuerySqlDialectPromptTest extends TestCase
{
    public function testExamplesUseConfiguredSqlDialect(): void
    {
        $service = new class () extends AIQueryService {
            public function __construct()
            {
            }

            public function prompt(): string
            {
                return $this->buildSystemPrompt('books and authors', 'List books');
            }
        };

        $previous = config('database.default');
        try {
            foreach (['sqlite', 'pgsql', 'sqlsrv'] as $driver) {
                config(['database.default' => $driver]);
                $prompt = $service->prompt();
                $this->assertStringContainsString("SQL dialect: {$driver}", $prompt);
                if ($driver !== 'sqlite') {
                    $this->assertStringNotContainsString('GROUP_CONCAT', $prompt);
                }
                if ($driver === 'sqlite') {
                    $this->assertStringNotContainsString('DISTINCT CONCAT(', $prompt);
                }
            }
        } finally {
            config(['database.default' => $previous]);
        }
    }
}
