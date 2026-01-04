<?php

namespace App\Traits;

trait IsolatesErrorHandlers
{
    protected function withHandlerIsolation(callable $callback)
    {
        $errorSentinel = static fn () => false;
        $exceptionSentinel = static fn () => null;

        $this->pushSentinelHandler('error', $errorSentinel);
        $this->pushSentinelHandler('exception', $exceptionSentinel);

        try {
            return $callback();
        } finally {
            $this->popToSentinel('exception', $exceptionSentinel);
            $this->popToSentinel('error', $errorSentinel);
        }
    }

    private function pushSentinelHandler(string $type, callable $sentinel): void
    {
        if ($type === 'exception') {
            if (function_exists('set_exception_handler')) {
                set_exception_handler($sentinel);
            }

            return;
        }

        if (function_exists('set_error_handler')) {
            set_error_handler($sentinel);
        }
    }

    private function popToSentinel(string $type, callable $sentinel): void
    {
        $restorer = $this->restorerForType($type);
        if ($restorer === null) {
            return;
        }

        while (true) {
            $current = $this->peekHandler($type);
            if ($current === null) {
                return;
            }

            if ($current === $sentinel) {
                $restorer();

                return;
            }

            $restorer();
        }
    }

    private function peekHandler(string $type)
    {
        if ($type === 'exception') {
            if (!function_exists('set_exception_handler') || !function_exists('restore_exception_handler')) {
                return null;
            }

            $probe = static fn () => null;
            $previous = set_exception_handler($probe);
            restore_exception_handler();

            return $previous;
        }

        if (!function_exists('set_error_handler') || !function_exists('restore_error_handler')) {
            return null;
        }

        $probe = static fn () => false;
        $previous = set_error_handler($probe);
        restore_error_handler();

        return $previous;
    }

    private function restorerForType(string $type): ?callable
    {
        if ($type === 'exception') {
            return function_exists('restore_exception_handler') ? 'restore_exception_handler' : null;
        }

        return function_exists('restore_error_handler') ? 'restore_error_handler' : null;
    }
}
