<?php

namespace App\Contracts;

interface ImportUIInterface
{
    /**
     * Initialize the UI with given dimensions
     */
    public function initialize(int $width, int $height): void;

    /**
     * Ask a question and return the user's text response
     */
    public function ask(string $question, string $default = ''): string;

    /**
     * Present a selection menu and return the selected option key
     */
    public function select(string $question, array $options, string $default = ''): string;

    /**
     * Ask a yes/no confirmation question
     */
    public function confirm(string $question, bool $default = false): bool;

    /**
     * Display a progress bar with callback for each iteration
     *
     * @param callable(int $index, mixed $item): void $callback
     */
    public function progress(string $label, iterable $items, callable $callback): void;

    /**
     * Show a spinner while executing a long-running callback
     *
     * @param callable(): mixed $callback
     */
    public function spin(string $message, callable $callback): mixed;

    /**
     * Display a table of data
     */
    public function table(array $headers, array $rows): void;

    /**
     * Display an info message
     */
    public function info(string $message): void;

    /**
     * Display a warning message
     */
    public function warning(string $message): void;

    /**
     * Display an error message
     */
    public function error(string $message): void;

    /**
     * Log a message to the activity log area
     */
    public function logMessage(string $message): void;

    /**
     * Update the progress indicator (e.g., "3 of 10 books")
     */
    public function updateProgress(int $current, int $total): void;

    /**
     * Set the current book metadata for display
     */
    public function setCurrentBook(array $metadata): void;

    /**
     * Draw the initial layout (for TUI modes)
     */
    public function drawInitialLayout(): void;

    /**
     * Clear the screen/display
     */
    public function clear(): void;

    /**
     * Set plain output mode (no TUI decorations)
     */
    public function setPlainMode(bool $plainMode): void;

    /**
     * Request an interrupt of the current operation
     */
    public function requestInterrupt(): void;

    /**
     * Restore terminal state after operations
     */
    public function restoreTerminalState(): void;
}
