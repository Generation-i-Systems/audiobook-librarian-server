<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\Message;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminMaintenanceService
{
    public function getAllUsers(): array
    {
        try {
            $users = User::all([
                'id',
                'name',
                'username',
                'email',
                'photo_url',
                'google_id',
                'role',
                'email_verified_at',
                'created_at',
                'updated_at',
                'last_login_at',
            ]);

            $apiLastUsed = $this->getMaxLastUsedByUser();

            $result = [];
            foreach ($users as $user) {
                $userId = (string) $user->id;
                $result[] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'photo_url' => $user->photo_url,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'google_id' => $user->google_id ?? null,
                    'last_login_at' => $user->last_login_at,
                    'last_used_at' => $this->maxTimestamp(
                        $user->last_login_at,
                        array_key_exists($userId, $apiLastUsed) ? $apiLastUsed[$userId] : null
                    ),
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('MySqlService getAllUsers failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Max last-used timestamp per user across Sanctum tokens and legacy api_tokens,
     * keyed by user id as string.
     *
     * @return array<string, string>
     */
    private function getMaxLastUsedByUser(): array
    {
        $sanctumLastUsed = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereNotNull('last_used_at')
            ->selectRaw('tokenable_id as user_id, MAX(last_used_at) as last_used_at')
            ->groupBy('tokenable_id')
            ->pluck('last_used_at', 'user_id');

        $legacyLastUsed = DB::table('api_tokens')
            ->whereNotNull('last_used_at')
            ->selectRaw('user_id, MAX(last_used_at) as last_used_at')
            ->groupBy('user_id')
            ->pluck('last_used_at', 'user_id');

        $combined = [];
        foreach ($sanctumLastUsed as $userId => $timestamp) {
            $key = (string) $userId;
            $existing = array_key_exists($key, $combined) ? $combined[$key] : null;
            $combined[$key] = $this->maxTimestamp($existing, $timestamp);
        }
        foreach ($legacyLastUsed as $userId => $timestamp) {
            $key = (string) $userId;
            $existing = array_key_exists($key, $combined) ? $combined[$key] : null;
            $combined[$key] = $this->maxTimestamp($existing, $timestamp);
        }

        return $combined;
    }

    private function maxTimestamp(?string $a, ?string $b): ?string
    {
        if (!$a) {
            return $b;
        }
        if (!$b) {
            return $a;
        }

        return Carbon::parse($a)->greaterThanOrEqualTo(Carbon::parse($b)) ? $a : $b;
    }

    public function deleteMessage(string $messageId): bool
    {
        try {
            $message = Message::where('id', $messageId)->first();

            if (!$message) {
                return false;
            }

            $message->delete();

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteMessage failed: ' . $e->getMessage());

            return false;
        }
    }

    public function deleteBook(string $bookId, bool $deleteFiles = true): bool
    {
        try {
            $book = Book::where('id', $bookId)->first();

            if (!$book) {
                Log::warning('Book not found for deletion', ['book_id' => $bookId]);

                return false;
            }

            $book->delete();

            Log::info('Book deleted from database', [
                'book_id' => $bookId,
                'delete_files' => $deleteFiles,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete book from database', [
                'book_id' => $bookId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
