<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Audit;
use App\Models\Book;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Read-only views of a book for the edit page: its audit trail as a timeline (first import included)
 * and every stored field the edit form does not show.
 */
class BookHistoryService
{
    /** Automatic directory checks: folded into one summary line instead of filling the timeline. */
    public const HOUSEKEEPING_FIELDS = ['directory_exists', 'directory_last_checked', 'last_library_json_update'];

    /** Fields the edit form already shows, so "other data" leaves them out. */
    public const FORM_FIELDS = [
        'title', 'description', 'directory_path', 'release_date', 'cover_image', 'needs_review', 'needs_review_reasons',
    ];

    /** Columns stored as 0/1 without a model cast. */
    private const BOOLEAN_FIELDS = ['needs_review', 'directory_exists', 'ai_processed'];

    private const IGNORED_FIELDS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** Upper bound on audit rows read for one book; old_values hold whole rows, so they can be large. */
    private const MAX_AUDITS = 500;

    /**
     * @return array{
     *     entries: list<array{event: string, label: string, at: ?Carbon, user: ?string, source: string,
     *         changes: list<array{field: string, old: ?string, new: ?string}>}>,
     *     housekeeping: array{count: int, last: ?Carbon},
     *     importRecorded: bool,
     *     addedAt: ?Carbon,
     *     truncated: bool
     * }
     */
    public function timeline(Book $book): array
    {
        $audits = Audit::query()
            ->with('user:id,name')
            ->where('auditable_type', $book->getMorphClass())
            ->where('auditable_id', $book->id)
            ->orderByDesc('id')
            ->limit(self::MAX_AUDITS + 1)
            ->get();
        $truncated = $audits->count() > self::MAX_AUDITS;

        $entries = [];
        $housekeepingCount = 0;
        $housekeepingLast = null;
        $importRecorded = false;

        foreach ($audits->take(self::MAX_AUDITS) as $audit) {
            $changes = $this->changes($audit);
            if ($audit->event === 'updated' && $changes === []) {
                $housekeepingCount++;
                $housekeepingLast ??= $audit->created_at;
                continue;
            }
            $importRecorded = $importRecorded || $audit->event === 'created';

            $entries[] = [
                'event' => $audit->event,
                'label' => match ($audit->event) {
                    'created' => __('First import'),
                    'deleted' => __('Deleted'),
                    default => __('Edited'),
                },
                'at' => $audit->created_at,
                'user' => $audit->user?->name,
                'source' => $this->source($audit),
                'changes' => $changes,
            ];
        }

        return [
            'entries' => $entries,
            'housekeeping' => ['count' => $housekeepingCount, 'last' => $housekeepingLast],
            'importRecorded' => $importRecorded,
            'addedAt' => $book->created_at,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return list<array{field: string, value: string}>
     */
    public function otherData(Book $book): array
    {
        $rows = [];
        foreach ($book->getAttributes() as $field => $raw) {
            if (in_array($field, self::FORM_FIELDS, true)) {
                continue;
            }
            $value = $this->format($field, $book->getAttributeValue($field));
            if ($field === 'publisher_id' && $value !== null && $book->publisher !== null) {
                $value .= ' (' . $book->publisher->name . ')';
            }
            $rows[] = ['field' => $field, 'value' => $value ?? ''];
        }

        return $rows;
    }

    /**
     * @return list<array{field: string, old: ?string, new: ?string}>
     */
    private function changes(Audit $audit): array
    {
        $new = $this->decode($audit->new_values);
        $old = $this->decode($audit->old_values);

        $changes = [];
        foreach ($new as $key => $value) {
            // created audits were written from toArray(), which uses camelCase keys
            $field = Str::snake((string) $key);
            if (in_array($field, self::HOUSEKEEPING_FIELDS, true) || in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }
            $newText = $this->format($field, $value);
            $oldText = $audit->event === 'updated' ? $this->format($field, $old[$field] ?? $old[$key] ?? null) : null;
            if ($audit->event === 'created' && $newText === null) {
                continue;
            }
            if ($audit->event === 'updated' && $oldText === $newText) {
                continue;
            }
            $changes[] = ['field' => $field, 'old' => $oldText, 'new' => $newText];
        }

        return $changes;
    }

    /**
     * Audit values were written json_encode()d into an `array`-cast column, so most rows hold a JSON
     * string inside JSON. Accept both that and a plain array.
     *
     * @return array<string, mixed>
     */
    private function decode(mixed $values): array
    {
        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        return is_array($values) ? $values : [];
    }

    private function format(string $field, mixed $value): ?string
    {
        if (is_string($value) && in_array(substr(ltrim($value), 0, 1), ['{', '['], true)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (in_array($field, self::BOOLEAN_FIELDS, true) || is_bool($value)) {
            return (bool) $value ? __('yes') : __('no');
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateTimeString();
        }

        return (string) $value;
    }

    private function source(Audit $audit): string
    {
        $path = (string) parse_url((string) $audit->url, PHP_URL_PATH);
        $path = preg_replace('#/\d+#', '/{id}', $path) ?? $path;

        return match (true) {
            $path === '' || $path === '/' => __('Import / command line'),
            str_ends_with($path, '/execute-immediate-move') => __('Folder move'),
            str_ends_with($path, '/autofill-from-path') => __('Autofill'),
            str_ends_with($path, '/resolve-duplicate') => __('Duplicate resolution'),
            str_ends_with($path, '/raw-json') => __('Raw JSON edit'),
            $path === '/admin/books/{id}' => __('Edit form'),
            default => $path,
        };
    }
}
