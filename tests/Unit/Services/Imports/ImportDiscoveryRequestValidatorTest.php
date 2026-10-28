<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Services\Imports\ImportApiException;
use App\Services\Imports\ImportDiscoveryRequestValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportDiscoveryRequestValidatorTest extends TestCase
{
    private function validator(): ImportDiscoveryRequestValidator
    {
        return app(ImportDiscoveryRequestValidator::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $entries): array
    {
        return ['mode' => 'scan', 'selections' => ['download'], 'entries' => $entries];
    }

    #[Test]
    public function aBigListingIsValidatedQuickly(): void
    {
        config(['import_drafts.discovery.max_entries' => 30000]);
        $entries = [];
        for ($i = 0; $i < 20000; $i++) {
            $entries[] = ['path' => "download/Book $i/01.mp3", 'kind' => 'file', 'bytes' => 1234];
        }

        $started = microtime(true);
        $valid = $this->validator()->validate($this->payload($entries));

        $this->assertCount(20000, $valid['entries']);
        $this->assertLessThan(1.5, microtime(true) - $started);
    }

    #[Test]
    public function aBadEntryIsReportedAgainstItsPosition(): void
    {
        $entries = [
            ['path' => 'download/a.mp3', 'kind' => 'file', 'bytes' => 5],
            ['path' => 'download/b.mp3', 'kind' => 'symlink', 'bytes' => -1],
            ['kind' => 'file'],
        ];

        try {
            $this->validator()->validate($this->payload($entries));
            $this->fail('expected a validation error');
        } catch (ImportApiException $error) {
            $fields = array_keys($error->details);
            $this->assertContains('entries.1.kind', $fields);
            $this->assertContains('entries.1.bytes', $fields);
            $this->assertContains('entries.2.path', $fields);
        }
    }
}
