<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\CheckCoverImages;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class CheckCoverImagesNormalizeNeedsReviewReasonsTest extends TestCase
{
    private function callNormalizeNeedsReviewReasons(mixed $value): array
    {
        $command = new CheckCoverImages();
        $method = new ReflectionMethod($command, 'normalizeNeedsReviewReasons');
        $method->setAccessible(true);

        /** @var array $result */
        $result = $method->invoke($command, $value);

        return $result;
    }

    #[Test]
    public function normalizeReturnsArrayAsIs(): void
    {
        $input = ['a', 'b'];
        $this->assertSame($input, $this->callNormalizeNeedsReviewReasons($input));
    }

    #[Test]
    public function normalizeDecodesJsonStringArray(): void
    {
        $input = '["a","b"]';
        $this->assertSame(['a', 'b'], $this->callNormalizeNeedsReviewReasons($input));
    }

    #[Test]
    public function normalizeReturnsEmptyArrayForInvalidJsonString(): void
    {
        $input = '{not json';
        $this->assertSame([], $this->callNormalizeNeedsReviewReasons($input));
    }

    #[Test]
    public function normalizeReturnsEmptyArrayForOtherTypes(): void
    {
        $this->assertSame([], $this->callNormalizeNeedsReviewReasons(null));
        $this->assertSame([], $this->callNormalizeNeedsReviewReasons(123));
        $this->assertSame([], $this->callNormalizeNeedsReviewReasons(true));
    }
}
