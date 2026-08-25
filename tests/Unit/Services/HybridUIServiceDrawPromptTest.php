<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HybridUIService;
use SoloTerm\Screen\Screen;
use Tests\TestCase;

class HybridUIServiceDrawPromptTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function drawPromptIsANoOpWhenPromptLinesIsEmpty(): void
    {
        $service = $this->makeServiceWithScreen();
        $before = $service->screenOutputForTest();

        $this->invokeDrawPrompt($service);

        $this->assertSame($before, $service->screenOutputForTest());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function drawPromptRendersPromptLinesWhenPopulated(): void
    {
        // Regression test: selectCoverWithPreview() (used for automatic cover-source
        // selection) is a raw-TTY prompt that populates $this->promptLines directly and
        // relies on drawPrompt() to draw it, unlike ask()/select()/confirm() which render
        // via Laravel Prompts instead. HybridUIService previously made drawPrompt() an
        // unconditional no-op (assuming only Prompts ever needed that space), which left
        // the "Select cover source" menu computed and waiting for input but never drawn —
        // the import looked frozen on any book with more than one cover source.
        $service = $this->makeServiceWithScreen();

        $reflection = new \ReflectionClass(HybridUIService::class);
        $promptLinesProp = $reflection->getProperty('promptLines');
        $promptLinesProp->setAccessible(true);
        $promptLinesProp->setValue($service, ['Select cover source']);

        $this->invokeDrawPrompt($service);

        $this->assertStringContainsString('Select cover source', $service->screenOutputForTest());
    }

    protected function makeServiceWithScreen(): HybridUIServiceTestDouble
    {
        $service = new HybridUIServiceTestDouble();

        $reflection = new \ReflectionClass(HybridUIService::class);
        $widthProp = $reflection->getProperty('width');
        $widthProp->setAccessible(true);
        $widthProp->setValue($service, 120);
        $heightProp = $reflection->getProperty('height');
        $heightProp->setAccessible(true);
        $heightProp->setValue($service, 40);

        $screenProp = $reflection->getProperty('screen');
        $screenProp->setAccessible(true);
        $screenProp->setValue($service, new Screen(120, 40));

        return $service;
    }

    protected function invokeDrawPrompt(HybridUIService $service): void
    {
        $reflection = new \ReflectionClass(HybridUIService::class);
        $method = $reflection->getMethod('drawPrompt');
        $method->setAccessible(true);
        $method->invoke($service);
    }
}

class HybridUIServiceTestDouble extends HybridUIService
{
    public function screenOutputForTest(): string
    {
        $reflection = new \ReflectionClass(HybridUIService::class);
        $screenProp = $reflection->getProperty('screen');
        $screenProp->setAccessible(true);
        $screen = $screenProp->getValue($this);

        return $screen->output();
    }
}
