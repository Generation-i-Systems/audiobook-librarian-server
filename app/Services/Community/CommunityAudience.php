<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\BlockedEntity;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The single "who may reach whom" policy for community features. A server is one
 * closed community: any user may reach any other user on it, except where a block
 * (in either direction) or a family-only restriction on either side forbids it.
 * Members may address groups they belong to; admins may address any group or everyone.
 */
class CommunityAudience
{
    /** Roles that can use the app; disabled, unverified and web-only accounts are not reachable. */
    private const ACTIVE_ROLES = ['library-user', 'librivox-user', 'hybrid-user', 'admin', 'super-admin'];

    public function canReachUser(User $sender, User $target): bool
    {
        return $this->isReachable($sender, $target, $this->blockedPairs($sender));
    }

    /**
     * @param Collection<int, int> $blocked
     */
    private function isReachable(User $sender, User $target, Collection $blocked): bool
    {
        if ($sender->id === $target->id || $blocked->contains($target->id)) {
            return false;
        }

        if (!in_array($target->role, self::ACTIVE_ROLES, true)) {
            return false;
        }

        if ($sender->isAdmin()) {
            return true;
        }

        if ($sender->community_family_only || $target->community_family_only) {
            return $sender->accountRootId() === $target->accountRootId();
        }

        return true;
    }

    public function canReachGroup(User $sender, Group $group): bool
    {
        if ($sender->isAdmin()) {
            return true;
        }

        return $group->members()->where('users.id', $sender->id)->exists();
    }

    public function canReachEveryone(User $sender): bool
    {
        return $sender->isAdmin();
    }

    /**
     * Everyone the sender may reach, ordered by display name.
     *
     * @return Collection<int, User>
     */
    public function reachableUsers(User $sender): Collection
    {
        $blocked = $this->blockedPairs($sender);

        return User::query()
            ->where('id', '!=', $sender->id)
            ->whereIn('role', self::ACTIVE_ROLES)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $this->isReachable($sender, $user, $blocked))
            ->values();
    }

    /**
     * Groups the sender may address: their own, or every group for an admin.
     *
     * @return Collection<int, Group>
     */
    public function reachableGroups(User $sender): Collection
    {
        $query = Group::query();
        if (!$sender->isAdmin()) {
            $query->whereHas('members', fn ($q) => $q->where('users.id', $sender->id));
        }

        return $query->withCount('members')->orderBy('name')->get();
    }

    /**
     * Members of a group the sender may reach, excluding the sender.
     *
     * @return Collection<int, User>
     */
    public function reachableGroupMembers(User $sender, Group $group): Collection
    {
        $blocked = $this->blockedPairs($sender);

        return $group->members()->get()
            ->filter(fn (User $user) => $this->isReachable($sender, $user, $blocked))
            ->values();
    }

    /**
     * Ids of users the given user has blocked or been blocked by.
     *
     * @return Collection<int, int>
     */
    private function blockedPairs(User $user): Collection
    {
        $blockedByMe = BlockedEntity::query()
            ->where('user_id', $user->id)
            ->where('entity_type', BlockedEntity::TYPE_USER)
            ->whereNotNull('entity_ref_id')
            ->pluck('entity_ref_id');

        $blockedMe = BlockedEntity::query()
            ->where('entity_type', BlockedEntity::TYPE_USER)
            ->where('entity_ref_id', $user->id)
            ->pluck('user_id');

        return $blockedByMe->merge($blockedMe)->map(fn ($id) => (int) $id)->unique()->values();
    }
}
