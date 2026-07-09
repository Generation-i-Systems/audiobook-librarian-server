<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by GalleryProxyClient when audiobook-librarian-www is unreachable
 * or times out. Rendered as a clean 502 (see bootstrap/app.php) rather than
 * letting the underlying HTTP client exception surface as a raw 500.
 */
class GalleryProxyUnavailableException extends \RuntimeException
{
}
