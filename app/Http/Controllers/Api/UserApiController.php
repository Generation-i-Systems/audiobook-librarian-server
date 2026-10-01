<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserApiController extends Controller
{
    /**
     * Get the current authenticated user's profile information.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? null,
            'permissions' => method_exists($user, 'permissionKeys') ? $user->permissionKeys() : [],
        ]);
    }

    /**
     * Full profile of the authenticated user (GET /user).
     */
    public function show(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $this->withGroupsAndPermissions($user);
    }

    /**
     * Update the authenticated user's own profile (PUT /user). Only name, username and
     * email are editable; every other field in the payload is ignored.
     */
    public function update(Request $request, DocumentStoreServiceInterface $documentStoreService): JsonResponse|User
    {
        /** @var User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required_without_all:username,email|string|max:255',
            'username' => 'required_without_all:name,email|string|max:255',
            'email' => 'required_without_all:name,username|string|email|max:255',
        ]);

        $validator->after(function ($validator) use ($request, $user, $documentStoreService): void {
            foreach (['email' => 'getUserByEmail', 'username' => 'getUserByUsername'] as $field => $lookup) {
                if (!is_string($request->input($field)) || $validator->errors()->has($field)) {
                    continue;
                }

                $existing = $documentStoreService->{$lookup}($request->input($field));
                if ($existing && (int) $existing['id'] !== $user->id) {
                    $validator->errors()->add($field, "The {$field} has already been taken.");
                }
            }
        });

        $data = $validator->validate();

        $user = $documentStoreService->updateUser((string) $user->id, $data);

        return $this->withGroupsAndPermissions($user);
    }

    private function withGroupsAndPermissions(User $user): User
    {
        $user->setAttribute('groups', $user->groups()->get(['groups.id', 'groups.name']));
        $user->setAttribute('permissions', $user->permissionKeys());

        return $user;
    }
}
