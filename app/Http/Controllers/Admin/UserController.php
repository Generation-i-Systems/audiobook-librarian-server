<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Contracts\DocumentStoreServiceInterface;

class UserController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
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
        return view('admin.users.create');
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => 'nullable|string|min:6|confirmed',
            'role' => 'required|string',
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

        $sendOtp = $request->boolean('send_otp_email', true);
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
            $apiController->sendOtp(request(), (string) $userId);
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

        return view('admin.users.edit', compact('user', 'activityData'));
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
            'role' => 'required|string',
            'password' => 'nullable|string|min:6|confirmed',
        ]);

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

        Log::info('UserController update before updateUser', [
            'user_id' => $id,
            'data_to_update' => $validated,
        ]);

        $this->documentStoreService->updateUser($id, $validated);

        $updatedUser = $this->documentStoreService->getUserById($id);
        Log::info('UserController update after updateUser call', [
            'user_id' => $id,
            'role_after_update' => $updatedUser['role'] ?? 'null',
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
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
        $user = $this->documentStoreService->getUserById($id);

        if (!$user) {
            return back()->with('error', 'User not found.');
        }

        if (($user['role'] ?? '') !== 'unverified') {
            return back()->with('info', 'User is already verified.');
        }

        $role = $request->input('role', 'user');
        if (!in_array($role, ['user', 'library-user', 'librivox-user', 'hybrid-user', 'admin', 'super-admin'], true)) {
            return back()->with('error', 'Invalid role selected.');
        }

        $this->documentStoreService->updateUser($id, [
            'role' => $role,
            'email_verified_at' => now()
        ]);

        return back()->with('success', 'User verified successfully.');
    }
}
