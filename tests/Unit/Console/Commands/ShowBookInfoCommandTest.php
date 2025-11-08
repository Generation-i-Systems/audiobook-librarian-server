<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\ShowBookInfo;
use App\Services\TerminalImageService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShowBookInfoCommandTest extends TestCase
{
    private ShowBookInfo $command;

    protected function setUp(): void
    {
        parent::setUp();

        $terminalImageService = new class extends TerminalImageService {
            public function supportsImages(): bool
            {
                return false;
            }
        };

        $this->command = new class ($terminalImageService) extends ShowBookInfo {
            public function __construct(TerminalImageService $terminalImageService)
            {
                parent::__construct($terminalImageService);
            }

            public function wrapTextPublic(string $text, int $maxWidth): string
            {
                return $this->wrapText($text, $maxWidth);
            }
        };
    }

    #[Test]
    public function itWrapsLongTokensWithoutSpaces(): void
    {
        $input = str_repeat('A', 120);
        $maxWidth = 20;

        $result = $this->command->wrapTextPublic($input, $maxWidth);
        $lines = explode("\n", $result);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual($maxWidth, mb_strlen($line));
        }

        $this->assertGreaterThan(1, count($lines));
    }

    #[Test]
    public function itPreservesColorTagsWhileWrapping(): void
    {
        $input = '<fg=red>' . str_repeat('B', 50) . '</>';
        $maxWidth = 10;

        $result = $this->command->wrapTextPublic($input, $maxWidth);
        $lines = explode("\n", $result);

        $visibleLengths = array_map(function (string $line): int {
            $visible = preg_replace('/<[^>]+>/', '', $line) ?? '';

            return mb_strlen($visible);
        }, $lines);

        foreach ($visibleLengths as $length) {
            $this->assertLessThanOrEqual($maxWidth, $length);
        }

        $this->assertStringContainsString('<fg=red>', $result);
        $this->assertStringContainsString('</>', $result);
    }
}
