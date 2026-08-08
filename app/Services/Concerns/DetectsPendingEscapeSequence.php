<?php

declare(strict_types=1);

namespace App\Services\Concerns;

trait DetectsPendingEscapeSequence
{
    /**
     * Terminal::read() (vendor/laravel/prompts) does one raw `fread(STDIN, 1024)`
     * with no escape-sequence disambiguation. Arrow keys and other CSI sequences
     * (e.g. "\e[B") normally arrive as a single multi-byte read, but under latency
     * (SSH, tmux, a slow terminal) the leading "\e" byte can land in its own read
     * before the rest of the sequence follows — which is byte-for-byte
     * indistinguishable from a genuine standalone Escape press. Since Escape now
     * cancels the prompt, a false positive here is far more disruptive than the
     * old no-op behavior. Give the rest of the sequence a brief window to arrive
     * before committing to "this was really Escape".
     */
    private function escapeStartsPendingSequence(): bool
    {
        $read = [STDIN];
        $write = [];
        $except = [];

        $ready = @stream_select($read, $write, $except, 0, 30_000);

        return $ready > 0;
    }
}
