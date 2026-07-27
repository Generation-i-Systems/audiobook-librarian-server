<?php

namespace Tests\Unit\Services;

use App\Services\ChapterDetectionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ChapterDetectionServiceTest extends TestCase
{
    #[Test]
    public function normalizeFfprobeChaptersReturnsLibrarianJsonChapterShape(): void
    {
        $service = new ChapterDetectionService();

        $chapters = $service->normalizeFfprobeChapters('disc-01/book.m4b', [
            [
                'start_time' => '0.000000',
                'end_time' => '12.500000',
                'tags' => ['title' => 'Opening'],
            ],
            [
                'start_time' => '12.500000',
                'end_time' => '42.000000',
                'tags' => [],
            ],
        ]);

        $this->assertSame([
            [
                'title' => 'Opening',
                'start' => 0.0,
                'duration' => 12.5,
                'file' => 'disc-01/book.m4b',
            ],
            [
                'title' => 'Chapter 2',
                'start' => 12.5,
                'duration' => 29.5,
                'file' => 'disc-01/book.m4b',
            ],
        ], $chapters);
    }

    #[Test]
    public function normalizeFfprobeChaptersSkipsChaptersWithoutStartTime(): void
    {
        $service = new ChapterDetectionService();

        $chapters = $service->normalizeFfprobeChapters('book.m4b', [
            ['end_time' => '10.000000', 'tags' => ['title' => 'Missing Start']],
            ['start_time' => '10.000000', 'tags' => ['title' => 'Valid']],
        ]);

        $this->assertSame([
            [
                'title' => 'Valid',
                'start' => 10.0,
                'duration' => null,
                'file' => 'book.m4b',
            ],
        ], $chapters);
    }
}
