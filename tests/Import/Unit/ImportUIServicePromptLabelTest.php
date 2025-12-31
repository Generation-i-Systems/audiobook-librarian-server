<?php

namespace Tests\Import\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServicePromptLabelTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function buildPromptLabelIncludesDefaultWhenProvided(): void
    {
        $service = new ImportUIService();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildPromptLabel');
        $method->setAccessible(true);

        $label = $method->invoke($service, 'Title', 'Old Title');

        $this->assertSame('Title [Old Title]:', $label);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildPromptLabelOmitsDefaultWhenEmpty(): void
    {
        $service = new ImportUIService();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildPromptLabel');
        $method->setAccessible(true);

        $label = $method->invoke($service, 'Title', '');

        $this->assertSame('Title:', $label);
    }
}
