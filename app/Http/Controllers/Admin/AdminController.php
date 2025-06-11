<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function updateRole(Request $request)
    {
        $request->validate([
            'user_id' => 'required|string',
            'role' => 'required|in:regular,admin',
        ]);
        $firestore = new \App\Services\FirestoreService();
        $userId = $request->input('user_id');
        $role = $request->input('role');
        // Assuming users are stored in a 'users' collection
        $firestore->getClient()->collection('users')->document($userId)->set([
            'role' => $role,
        ], ['merge' => true]);

        return back()->with('success', 'User role updated successfully!');
    }

    public function index()
    {
        return view('admin.index');
    }
}
