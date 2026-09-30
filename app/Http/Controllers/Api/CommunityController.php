<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Services\Community\CommunityAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Who the current user can share with on this server, plus their own community
 * settings. Parents (and admins) can restrict a managed family member to family-only.
 */
class CommunityController extends Controller
{
    public function __construct(private readonly CommunityAudience $audience)
    {
    }

    /** GET /community/people — reachable users and addressable groups */
    public function people(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $people = $this->audience->reachableUsers($user)->map(function (User $person) use ($user) {
            $photo = $person->getRawOriginal('photo_url');

            return [
                'id' => $person->id,
                'name' => $person->name,
                'photo_url' => is_string($photo) ? $photo : null,
                'is_family' => $person->accountRootId() === $user->accountRootId(),
            ];
        });

        $groups = $this->audience->reachableGroups($user)->map(fn (Group $group) => [
            'id' => $group->id,
            'name' => $group->name,
            'member_count' => (int) ($group->members_count ?? 0),
            'is_member' => $group->members()->where('users.id', $user->id)->exists(),
        ]);

        return response()->json([
            'users' => $people->values(),
            'groups' => $groups->values(),
            'can_send_to_everyone' => $this->audience->canReachEveryone($user),
        ]);
    }

    /** GET /community/settings */
    public function settings(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json($this->formatSettings($user));
    }

    /** PUT /community/settings — the user's own sharing choices */
    public function updateSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $data = $request->validate([
            'share_progress' => ['required', 'boolean'],
        ]);

        $user->community_share_progress = (bool) $data['share_progress'];
        $user->save();

        return response()->json($this->formatSettings($user));
    }

    /** PUT /community/members/{member}/family-only — parent or admin limits a family member */
    public function setFamilyOnly(Request $request, User $member): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $isParent = $member->parent_user_id !== null && $member->parent_user_id === $user->id;
        if (!$user->isAdmin() && !$isParent) {
            return response()->json(['message' => 'Only the account parent or an admin can change this.'], 403);
        }

        $data = $request->validate([
            'family_only' => ['required', 'boolean'],
        ]);

        $member->community_family_only = (bool) $data['family_only'];
        $member->save();

        return response()->json(['id' => $member->id, 'family_only' => $member->community_family_only]);
    }

    /**
     * @return array{share_progress: bool, family_only: bool}
     */
    private function formatSettings(User $user): array
    {
        return [
            'share_progress' => (bool) $user->community_share_progress,
            'family_only' => (bool) $user->community_family_only,
        ];
    }
}
