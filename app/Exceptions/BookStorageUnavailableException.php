<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The book storage root (BOOK_STORAGE_PATH) is missing or unmounted. Rendered as a clean
 * 503; the configured path is kept off the message and only logged server-side.
 */
class BookStorageUnavailableException extends HttpException
{
    public const MESSAGE = 'The book storage is temporarily unavailable. Please try again shortly.';

    public function __construct(public readonly string $root, ?\Throwable $previous = null)
    {
        parent::__construct(503, self::MESSAGE, $previous);
    }

    public static function forRoot(?string $root, ?\Throwable $previous = null): self
    {
        \Illuminate\Support\Facades\Log::error('Book storage root is not accessible', ['root' => $root]);

        return new self((string) $root, $previous);
    }

    /**
     * Friendly page for browser requests; null lets API/JSON requests fall through to the
     * global JSON error renderer.
     */
    public function render(Request $request): ?Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return null;
        }

        return response()->view('errors.storage-unavailable', ['message' => self::MESSAGE], 503);
    }
}
