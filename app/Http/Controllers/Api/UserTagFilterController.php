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
 * Self-service require/ban tag filters — a user managing their own content filter.
 * See AdminUserTagFilterController for the admin-locked variant.
 */
class UserTagFilterController extends Controller
{
    public function __construct(private readonly UserTagFilterService $service)
    {
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json(['data' => UserTagFilter::where('user_id', $user->id)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tag' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::in([UserTagFilter::MODE_REQUIRE, UserTagFilter::MODE_BAN])],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $filter = $this->service->setFilter($user, $data['tag'], $data['mode'], lockedByAdmin: false, actingAsAdmin: false);

        return response()->json(['data' => $filter], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $this->service->removeFilter($user, $id, actingAsAdmin: false);

        return response()->json(['message' => 'Tag filter removed.']);
    }
}
