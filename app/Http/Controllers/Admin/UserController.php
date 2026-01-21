<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|string',
        ]);
        // Uniqueness check
        if ($this->documentStoreService->userExistsByUsername($validated['username'])) {
            return back()->withErrors(['username' => 'Username already exists.']);
        }
        if ($this->documentStoreService->userExistsByEmail($validated['email'])) {
            return back()->withErrors(['email' => 'Email already exists.']);
        }
        // Never store password_confirmation on user record
        unset($validated['password_confirmation']);
        $validated['password'] = Hash::make($validated['password']);
        $this->documentStoreService->createUser($validated);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }


    public function edit($id)
    {
        $user = $this->documentStoreService->getUserById($id);
        if (!$user) {
            abort(404);
        }

        return view('admin.users.edit', compact('user'));
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
        // Uniqueness check (ignore current user)
        $existingUser = $this->documentStoreService->getUserByUsername($validated['username']);
        if ($existingUser && $existingUser['id'] !== $id) {
            return back()->withErrors(['username' => 'Username already exists.']);
        }
        $existingEmail = $this->documentStoreService->getUserByEmail($validated['email']);
        if ($existingEmail && $existingEmail['id'] !== $id) {
            return back()->withErrors(['email' => 'Email already exists.']);
        }
        // Never store password_confirmation on user record
        unset($validated['password_confirmation']);
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }
        $this->documentStoreService->updateUser($id, $validated);

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
    public function verify($id)
    {
        $user = $this->documentStoreService->getUserById($id);

        if (!$user) {
            return back()->with('error', 'User not found.');
        }

        if (($user['role'] ?? '') !== 'unverified') {
            return back()->with('info', 'User is already verified.');
        }

        $this->documentStoreService->updateUser($id, [
            'role' => 'user',
            'email_verified_at' => now()
        ]);

        return back()->with('success', 'User verified successfully.');
    }
}
