<?php

namespace Tests\Core\Unit\Support;

use App\Support\DateNormalizer;
use PHPUnit\Framework\TestCase;

class DateNormalizerTest extends TestCase
{
    public function test_normalizes_iso_date(): void
    {
        $this->assertSame('2024-05-10', DateNormalizer::normalize('2024-05-10'));
    }

    public function test_normalizes_iso_datetime(): void
    {
        $this->assertSame('2024-05-10', DateNormalizer::normalize('2024-05-10T13:45:00Z'));
    }

    public function test_normalizes_slash_dates_preferring_mdY(): void
    {
        $this->assertSame('2024-05-10', DateNormalizer::normalize('05/10/2024'));
        // when first part > 12, interpret as d/m/Y
        $this->assertSame('2024-10-05', DateNormalizer::normalize('10/05/2024'));
    }

    public function test_normalizes_dash_dmy(): void
    {
        $this->assertSame('2024-05-10', DateNormalizer::normalize('10-05-2024'));
    }

    public function test_returns_null_on_invalid(): void
    {
        $this->assertNull(DateNormalizer::normalize('not a date'));
        $this->assertNull(DateNormalizer::normalize(null));
        $this->assertNull(DateNormalizer::normalize(''));
    }
}
