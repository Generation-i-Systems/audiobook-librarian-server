<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Traits\IsolatesErrorHandlers;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IsolatesErrorHandlersTest extends TestCase
{
    #[Test]
    public function itRestoresErrorHandlerToBaselineAfterCallback(): void
    {
        $before = $this->peekErrorHandler();

        $subject = new class () {
            use IsolatesErrorHandlers;

            public function run(callable $callback)
            {
                return $this->withHandlerIsolation($callback);
            }
        };

        $leakyHandler = static fn (): bool => false;

        $subject->run(static function () use ($leakyHandler): void {
            set_error_handler($leakyHandler);
        });

        $after = $this->peekErrorHandler();

        self::assertSame($before, $after);
    }

    #[Test]
    public function itRestoresExceptionHandlerToBaselineAfterCallback(): void
    {
        $before = $this->peekExceptionHandler();

        $subject = new class () {
            use IsolatesErrorHandlers;

            public function run(callable $callback)
            {
                return $this->withHandlerIsolation($callback);
            }
        };

        $leakyHandler = static fn (\Throwable $throwable): never => throw $throwable;

        $subject->run(static function () use ($leakyHandler): void {
            set_exception_handler($leakyHandler);
        });

        $after = $this->peekExceptionHandler();

        self::assertSame($before, $after);
    }

    private function peekErrorHandler(): mixed
    {
        $probe = static fn (): bool => false;
        $previous = set_error_handler($probe);
        restore_error_handler();

        return $previous;
    }

    private function peekExceptionHandler(): mixed
    {
        $probe = static function (\Throwable $throwable): void {
        };
        $previous = set_exception_handler($probe);
        restore_exception_handler();

        return $previous;
    }
}
