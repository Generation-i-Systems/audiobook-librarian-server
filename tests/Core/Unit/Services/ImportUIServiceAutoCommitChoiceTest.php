<?php

namespace Tests\Core\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceAutoCommitChoiceTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function shouldAutoCommitChoiceReturnsTrueWhenExactMatchAndNotAmbiguous(): void
    {
        $reflection = new \ReflectionClass(ImportUIService::class);
        $method = $reflection->getMethod('shouldAutoCommitChoice');
        $method->setAccessible(true);

        $options = [
            '1' => 'One',
            '2' => 'Two',
        ];

        $this->assertTrue($method->invoke(null, '1', $options));
        $this->assertTrue($method->invoke(null, '2', $options));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function shouldAutoCommitChoiceReturnsFalseWhenAmbiguousPrefixExists(): void
    {
        $reflection = new \ReflectionClass(ImportUIService::class);
        $method = $reflection->getMethod('shouldAutoCommitChoice');
        $method->setAccessible(true);

        $options = [
            '1' => 'One',
            '10' => 'Ten',
            '11' => 'Eleven',
        ];

        $this->assertFalse($method->invoke(null, '1', $options));
        $this->assertTrue($method->invoke(null, '10', $options));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function shouldAutoCommitChoiceReturnsFalseWhenNotAnOption(): void
    {
        $reflection = new \ReflectionClass(ImportUIService::class);
        $method = $reflection->getMethod('shouldAutoCommitChoice');
        $method->setAccessible(true);

        $options = [
            'a' => 'Alpha',
            'b' => 'Beta',
        ];

        $this->assertFalse($method->invoke(null, 'c', $options));
        $this->assertFalse($method->invoke(null, '', $options));
    }
}
