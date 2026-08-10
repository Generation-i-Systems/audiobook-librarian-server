<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\User;
use App\Services\BookTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TagController extends Controller
{
    public function __construct(private readonly BookTagService $bookTagService)
    {
    }

    public function update(Request $request, Book $book): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'in:system,group,user'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id', 'required_if:scope,group'],
            'tags' => ['nullable', 'string'],
        ]);

        $user = User::find(Auth::id());
        if (!$user) {
            abort(403, 'Authenticated user record not found.');
        }
        $tags = $this->splitTags($validated['tags'] ?? '');

        try {
            $result = $this->bookTagService->updateTags(
                $user,
                $book,
                $validated['scope'],
                $validated['group_id'] ?? null,
                $tags,
            );
        } catch (HttpExceptionInterface $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'You are not allowed to set these tags.',
                ], $e->getStatusCode());
            }

            return back()->with('error', $e->getMessage() ?: 'You are not allowed to set these tags.');
        }

        if ($request->expectsJson()) {
            return response()->json(array_merge($result, ['message' => 'Tags updated successfully!']));
        }

        return back()->with('success', 'Tags updated successfully!');
    }

    /**
     * @return array<int, string>
     */
    private function splitTags(string $rawTags): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[,\n]+/', $rawTags) ?: []),
            fn (string $tag): bool => $tag !== '',
        ));
    }
}
