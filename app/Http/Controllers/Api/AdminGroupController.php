<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminGroupController extends Controller
{
    /**
     * GET /api/admin/groups
     */
    public function index(): JsonResponse
    {
        $groups = Group::query()
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (Group $group) => $this->formatGroup($group));

        return response()->json(['groups' => $groups]);
    }

    /**
     * GET /api/admin/groups/{group}
     */
    public function show(Group $group): JsonResponse
    {
        $group->load('members:id,name,email');

        return response()->json($this->formatGroup($group, includeMembers: true));
    }

    /**
     * POST /api/admin/groups
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $group = Group::query()->create([
            'name' => trim((string) $request->input('name')),
        ]);

        return response()->json($this->formatGroup($group), 201);
    }

    /**
     * POST /api/admin/groups/{group}/members
     */
    public function addMember(Request $request, Group $group): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $userId = (int) $request->input('user_id');

        $group->members()->syncWithoutDetaching([
            $userId => ['added_by_user_id' => $request->user()->id],
        ]);

        $group->load('members:id,name,email');

        return response()->json($this->formatGroup($group, includeMembers: true));
    }

    /**
     * DELETE /api/admin/groups/{group}/members/{user}
     */
    public function removeMember(Group $group, User $user): JsonResponse
    {
        $group->members()->detach($user->id);

        $group->load('members:id,name,email');

        return response()->json($this->formatGroup($group, includeMembers: true));
    }

    private function formatGroup(Group $group, bool $includeMembers = false): array
    {
        $data = [
            'id' => $group->id,
            'name' => $group->name,
            'memberCount' => $group->members_count ?? $group->members()->count(),
        ];

        if ($includeMembers) {
            $data['members'] = $group->members->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values();
        }

        return $data;
    }
}
