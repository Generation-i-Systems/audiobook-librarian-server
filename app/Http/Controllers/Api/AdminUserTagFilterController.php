<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserTagFilter;
use App\Services\UserTagFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-managed require/ban tag filters on another user's account — locked, so the
 * owning user cannot change or remove them. See UserTagFilterController for the
 * self-service variant.
 */
class AdminUserTagFilterController extends Controller
{
    public function __construct(private readonly UserTagFilterService $service)
    {
    }

    public function index(int $userId): JsonResponse
    {
        $target = User::findOrFail($userId);

        return response()->json(['data' => UserTagFilter::where('user_id', $target->id)->get()]);
    }

    public function store(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'tag' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::in([UserTagFilter::MODE_REQUIRE, UserTagFilter::MODE_BAN])],
        ]);

        $target = User::findOrFail($userId);
        $filter = $this->service->setFilter($target, $data['tag'], $data['mode'], lockedByAdmin: true, actingAsAdmin: true);

        return response()->json(['data' => $filter], 201);
    }

    public function destroy(int $userId, int $id): JsonResponse
    {
        $target = User::findOrFail($userId);
        $this->service->removeFilter($target, $id, actingAsAdmin: true);

        return response()->json(['message' => 'Tag filter removed.']);
    }
}
