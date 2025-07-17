<?php

/**
 * This file exists solely to provide IDE type hints for Mockery
 *
 * @noinspection PhpIllegalPsrClassPathInspection
 */

namespace Mockery {
    /**
     * IDE helper for Mockery
     *
     * @method static \Mockery\MockInterface|\Mockery\LegacyMockInterface mock(string $class, ...$args)
     * @method static \Mockery\Expectation shouldReceive(string $method)
     * @method static \Mockery\Expectation with(...$args)
     * @method static \Mockery\Expectation andReturn(mixed $value)
     * @method static \Mockery\Expectation once()
     * @method static \Mockery\Expectation never()
     * @method static \Mockery\Expectation times(int $times)
     * @method static \Mockery\Expectation andReturnNull()
     */
    class MockInterface
    {
    }
}
