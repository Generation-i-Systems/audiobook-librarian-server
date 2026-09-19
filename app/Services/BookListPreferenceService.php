<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Remembers the Books page display state (view type, per page, sort) per user.
 *
 * Authenticated users persist to users.book_list_preferences; the session is
 * always written too so guests and pre-migration requests keep working.
 */
class BookListPreferenceService
{
    public const VIEW_TYPES = ['grid', 'compact', 'list'];

    public const PER_PAGE_OPTIONS = [12, 24, 36, 48, 72, 96];

    public const SORTS = [
        'recent_desc', 'recent_asc', 'title_asc', 'title_desc', 'author_asc', 'author_desc',
        'series_asc', 'series_desc', 'genre_asc', 'genre_desc', 'year_asc', 'year_desc',
    ];

    /** Maps preference key to its session key. */
    private const SESSION_KEYS = [
        'view_type' => 'main_view_type',
        'per_page' => 'main_per_page',
        'sort' => 'main_sort',
    ];

    public function normalize(string $key, mixed $value): string|int|null
    {
        return match ($key) {
            'view_type' => is_string($value) && in_array($value, self::VIEW_TYPES, true) ? $value : null,
            'per_page' => is_numeric($value) && in_array((int) $value, self::PER_PAGE_OPTIONS, true) ? (int) $value : null,
            'sort' => is_string($value) && in_array($value, self::SORTS, true) ? $value : null,
            default => null,
        };
    }

    /**
     * @return bool false when the key or value is not a valid preference
     */
    public function set(Request $request, string $key, mixed $value): bool
    {
        $normalized = $this->normalize($key, $value);
        if ($normalized === null) {
            return false;
        }

        $request->session()->put(self::SESSION_KEYS[$key], $normalized);

        $user = $request->user();
        if ($user instanceof User) {
            $stored = is_array($user->book_list_preferences) ? $user->book_list_preferences : [];
            $stored[$key] = $normalized;
            $user->forceFill(['book_list_preferences' => $stored])->save();
        }

        return true;
    }

    public function get(Request $request, string $key, string|int|null $default = null): string|int|null
    {
        $user = $request->user();
        if ($user instanceof User && is_array($user->book_list_preferences)) {
            $saved = $this->normalize($key, $user->book_list_preferences[$key] ?? null);
            if ($saved !== null) {
                return $saved;
            }
        }

        $sessionKey = self::SESSION_KEYS[$key] ?? null;
        if ($sessionKey !== null && $request->hasSession()) {
            $saved = $this->normalize($key, $request->session()->get($sessionKey));
            if ($saved !== null) {
                return $saved;
            }
        }

        return $default;
    }
}
