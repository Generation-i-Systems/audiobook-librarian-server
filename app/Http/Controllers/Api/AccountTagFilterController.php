<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserTagFilter;
use App\Services\UserTagFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Management of system (account-wide) tag filters by a non-admin account parent or a
 * member the parent has designated as a filter manager (User::is_filter_manager).
 * A full admin may also use these routes. See AdminUserTagFilterController for the
 * equivalent admin-only endpoint, and UserTagFilterController for the self-service
 * personal-filter variant.
 */
class AccountTagFilterController extends Controller
{
    public function __construct(private readonly UserTagFilterService $service)
    {
    }

    public function index(int $userId): JsonResponse
    {
        $target = User::findOrFail($userId);
        $this->authorizeAccountManagement($target);

        return response()->json(['data' => $this->service->systemFiltersForAccount($target)->values()]);
    }

    public function store(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'tag' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::in([UserTagFilter::MODE_REQUIRE, UserTagFilter::MODE_BAN])],
        ]);

        $target = User::findOrFail($userId);
        /** @var User $actor */
        $actor = Auth::user();
        $filter = $this->service->setFilter($actor, $target, $data['tag'], $data['mode'], UserTagFilter::SCOPE_SYSTEM);

        return response()->json(['data' => $filter], 201);
    }

    public function destroy(int $userId, int $id): JsonResponse
    {
        $target = User::findOrFail($userId);
        /** @var User $actor */
        $actor = Auth::user();
        $this->service->removeFilter($actor, $target, $id, UserTagFilter::SCOPE_SYSTEM);

        return response()->json(['message' => 'Tag filter removed.']);
    }

    private function authorizeAccountManagement(User $target): void
    {
        /** @var User $actor */
        $actor = Auth::user();
        if (!$actor->canManageSystemFiltersFor($target)) {
            abort(403, 'You are not authorized to manage tag filters for this account.');
        }
    }
}
