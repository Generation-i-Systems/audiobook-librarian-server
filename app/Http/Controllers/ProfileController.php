<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function index()
    {
        $firestore = new FirestoreService;
        $userId = Auth::id();
        $userDoc = $firestore->getClient()->collection('users')->document($userId)->snapshot();
        $user = $userDoc->exists() ? $userDoc->data() : null;
        if ($user) {
            $user['id'] = $userId;
        }

        return view('profile.index', compact('user'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.Auth::id(),
        ]);

        $firestore = new FirestoreService;
        $userId = Auth::id();
        $firestore->getClient()->collection('users')->document($userId)->set([
            'name' => $request->name,
            'email' => $request->email,
        ], ['merge' => true]);

        return back()->with('success', 'Profile updated successfully!');
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $firestore = new FirestoreService;
        $userId = Auth::id();
        $userDoc = $firestore->getClient()->collection('users')->document($userId)->snapshot();
        $user = $userDoc->exists() ? $userDoc->data() : null;
        if (! $user || ! Hash::check($request->current_password, $user['password'])) {
            return back()->withErrors(['current_password' => 'Incorrect current password.']);
        }
        $firestore->getClient()->collection('users')->document($userId)->set([
            'password' => Hash::make($request->password),
        ], ['merge' => true]);

        return back()->with('success', 'Password changed successfully!');
    }

    public function requestAdminPermissions(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
        ]);

        $firestore = new FirestoreService;
        $userId = Auth::id();
        $firestore->getClient()->collection('messages')->add([
            'user_id' => $userId,
            'content' => $request->input('content'),
            'is_from_admin' => false,
        ]);

        return back()->with('success', 'Admin permission request sent!');
    }
}
