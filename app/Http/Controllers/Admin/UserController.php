<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $firestore = new FirestoreService();
        $users = $firestore->getClient()->collection('users')->documents();
        $userList = [];
        foreach ($users as $userDoc) {
            if ($userDoc->exists()) {
                $user = $userDoc->data();
                $user['id'] = $userDoc->id();
                $userList[] = $user;
            }
        }
        return view('admin.users.index', ['users' => $userList]);
    }

    public function create()
    {
        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $firestore = new FirestoreService();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|string',
        ]);
        // Uniqueness check
        $existingUser = $firestore->getClient()->collection('users')
            ->where('username', '=', $validated['username'])
            ->documents();
        foreach ($existingUser as $doc) {
            if ($doc->exists()) {
                return back()->withErrors(['username' => 'Username already exists.']);
            }
        }
        $existingEmail = $firestore->getClient()->collection('users')
            ->where('email', '=', $validated['email'])
            ->documents();
        foreach ($existingEmail as $doc) {
            if ($doc->exists()) {
                return back()->withErrors(['email' => 'Email already exists.']);
            }
        }
        // Never store password_confirmation on user record
        unset($validated['password_confirmation']);
        $validated['password'] = Hash::make($validated['password']);
        $firestore->getClient()->collection('users')->add($validated);
        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function edit($id)
    {
        $firestore = new FirestoreService();
        $userDoc = $firestore->getClient()->collection('users')->document($id)->snapshot();
        if (!$userDoc->exists()) {
            abort(404);
        }
        $user = $userDoc->data();
        $user['id'] = $userDoc->id();
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, $id)
    {
        $firestore = new FirestoreService();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'email' => 'required|email',
            'role' => 'required|string',
            'password' => 'nullable|string|min:6|confirmed',
        ]);
        // Uniqueness check (ignore current user)
        $existingUser = $firestore->getClient()->collection('users')
            ->where('username', '=', $validated['username'])
            ->documents();
        foreach ($existingUser as $doc) {
            if ($doc->exists() && $doc->id() !== $id) {
                return back()->withErrors(['username' => 'Username already exists.']);
            }
        }
        $existingEmail = $firestore->getClient()->collection('users')
            ->where('email', '=', $validated['email'])
            ->documents();
        foreach ($existingEmail as $doc) {
            if ($doc->exists() && $doc->id() !== $id) {
                return back()->withErrors(['email' => 'Email already exists.']);
            }
        }
        // Never store password_confirmation on user record
        unset($validated['password_confirmation']);
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }
        $firestore->getClient()->collection('users')->document($id)->set($validated, ['merge' => true]);
        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }

    public function destroy($id)
    {
        $firestore = new FirestoreService();
        $firestore->getClient()->collection('users')->document($id)->delete();
        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }
}
