<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Enums\PermissionKey;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTagFilter;
use App\Services\UserTagFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        private readonly UserTagFilterService $userTagFilterService,
    ) {
        $this->documentStoreService = $documentStoreService;
    }


    public function index(Request $request)
    {
        // Store the current URL as the last viewed list for redirects after edit/update
        session(['last_admin_list_url' => $request->fullUrl()]);

        $users = $this->documentStoreService->getAllUsers();

        return view('admin.users.index', ['users' => $users]);
    }


    public function create()
    {
        return view('admin.users.create', ['assignableRoles' => Role::assignable()]);
    }


    public function store(Request $request)
    {
        $sendOtpRequested = $request->boolean('send_otp_email', true);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => [$sendOtpRequested ? 'nullable' : 'required', 'string', 'min:6', 'confirmed'],
            'role' => ['required', 'string', Rule::in(Role::keyList())],
            'send_otp_email' => 'sometimes|boolean',
        ]);
        // Uniqueness check
        if ($this->documentStoreService->userExistsByUsername($validated['username'])) {
            return back()->withErrors(['username' => 'Username already exists.']);
        }
        if ($this->documentStoreService->userExistsByEmail($validated['email'])) {
            return back()->withErrors(['email' => 'Email already exists.']);
        }
        unset($validated['password_confirmation']);

        $sendOtp = $sendOtpRequested;
        $hasPassword = !empty($validated['password']);

        $userData = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $hasPassword ? Hash::make($validated['password']) : Hash::make(\Illuminate\Support\Str::random(32)),
            'role' => $validated['role'],
            'must_change_password' => !$hasPassword || $sendOtp,
        ];

        $userId = $this->documentStoreService->createUser($userData);

        if ($userId && $sendOtp) {
            $apiController = app(AdminUserController::class);
            $apiController->sendWelcome(request(), (string) $userId);
        }

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.' . ($sendOtp ? ' A sign-in email has been sent.' : ''));
    }


    public function edit($id)
    {
        $user = $this->documentStoreService->getUserById($id);
        if (!$user) {
            abort(404);
        }

        $activityData = $this->documentStoreService->getUserActivityData($id);
        $adminTagFilters = UserTagFilter::query()
            ->where('user_id', $id)
            ->where('locked_by_admin', true)
            ->get()
            ->groupBy('mode');

        $allPermissions = Permission::query()->orderBy('label')->get();
        $eloquentUser = User::findOrFail($id);
        $userPermissionKeys = $eloquentUser->permissions()->pluck('permissions.key')->all();
        $rolePermissionKeys = $eloquentUser->authRole?->permissionKeys() ?? [];
        $roleLabel = $eloquentUser->authRole?->label;

        return view(
            'admin.users.edit',
            compact(
                'user',
                'activityData',
                'adminTagFilters',
                'allPermissions',
                'userPermissionKeys',
                'rolePermissionKeys',
                'roleLabel'
            ) + ['assignableRoles' => Role::assignable()]
        );
    }

    public function show($id)
    {
        $user = $this->documentStoreService->getUserById($id);
        if (!$user) {
            abort(404);
        }

        $activityData = $this->documentStoreService->getUserActivityData($id);

        return view('admin.users.show', compact('user', 'activityData'));
    }

    public function profile()
    {
        $id = auth()->id();
        $user = $this->documentStoreService->getUserById($id);
        if (!$user) {
            abort(404);
        }

        $activityData = $this->documentStoreService->getUserActivityData($id);

        return view('admin.users.show', compact('user', 'activityData'));
    }


    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'email' => 'required|email',
            'role' => ['required', 'string', Rule::in(Role::keyList())],
            'password' => 'nullable|string|min:6|confirmed',
            'required_tags' => 'nullable|string|max:2000',
            'ignored_tags' => 'nullable|string|max:2000',
        ]);

        $requiredTags = UserTagFilterService::parseTagList($validated['required_tags'] ?? null);
        $ignoredTags = UserTagFilterService::parseTagList($validated['ignored_tags'] ?? null);
        if (array_intersect($requiredTags, $ignoredTags) !== []) {
            return back()
                ->withInput()
                ->withErrors(['ignored_tags' => 'A tag cannot be both required and ignored.']);
        }

        Log::info('UserController update called', [
            'user_id' => $id,
            'validated_data' => $validated,
        ]);

        // Uniqueness check (ignore current user)
        $existingUser = $this->documentStoreService->getUserByUsername($validated['username']);
        if ($existingUser && (string)$existingUser['id'] !== (string)$id) {
            return back()->withErrors(['username' => 'Username already exists.']);
        }
        $existingEmail = $this->documentStoreService->getUserByEmail($validated['email']);
        if ($existingEmail && (string)$existingEmail['id'] !== (string)$id) {
            return back()->withErrors(['email' => 'Email already exists.']);
        }
        // Never store password_confirmation on user record
        unset($validated['password_confirmation']);
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }
        unset($validated['required_tags'], $validated['ignored_tags']);

        Log::info('UserController update before updateUser', [
            'user_id' => $id,
            'data_to_update' => $validated,
        ]);

        $this->documentStoreService->updateUser($id, $validated);
        $this->userTagFilterService->replaceAdminFilters(
            User::findOrFail($id),
            $requiredTags,
            $ignoredTags,
        );

        $updatedUser = $this->documentStoreService->getUserById($id);
        Log::info('UserController update after updateUser call', [
            'user_id' => $id,
            'role_after_update' => $updatedUser['role'] ?? 'null',
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }


    public function sendOtp(Request $request, $id)
    {
        $user = $this->documentStoreService->getUserById($id);
        if (!$user) {
            return back()->with('error', 'User not found.');
        }

        $response = app(AdminUserController::class)->sendOtp($request, (string) $id);

        if ($response->getStatusCode() !== 200) {
            return back()->with('error', $response->getData()->message ?? 'Failed to send sign-in email.');
        }

        return back()->with('success', $response->getData()->message);
    }

    public function generateLoginQr(Request $request, $id)
    {
        return app(AdminUserController::class)->generateLoginQr($request, (string) $id);
    }

    public function updatePermissions(Request $request, $id)
    {
        $validated = $request->validate([
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:' . implode(',', array_map(
                fn (PermissionKey $permissionKey) => $permissionKey->value,
                PermissionKey::cases()
            )),
        ]);

        $permissionIds = Permission::query()
            ->whereIn('key', $validated['permissions'] ?? [])
            ->pluck('id');

        User::findOrFail($id)->permissions()->sync($permissionIds);

        return back()->with('success', 'Permissions updated successfully.');
    }

    public function destroy($id)
    {
        $this->documentStoreService->deleteUser($id);

        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }

    /**
     * Verify a user account
     *
     * @param string $id The user ID to verify
     * @return \Illuminate\Http\RedirectResponse
     */
    public function verify(Request $request, $id)
    {
        $response = app(AdminUserController::class)->verify($request, (string) $id);
        $data = $response->getData();

        if ($response->getStatusCode() >= 400) {
            return back()->with('error', $data->message ?? 'Failed to verify user.');
        }

        if (!isset($data->user)) {
            return back()->with('info', $data->message ?? 'User is already verified.');
        }

        return back()->with('success', $data->message ?? 'User verified successfully.');
    }
}
