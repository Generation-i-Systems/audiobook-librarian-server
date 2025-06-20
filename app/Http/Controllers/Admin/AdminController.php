<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Contracts\DocumentStoreServiceInterface;

class AdminController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }
    public function updateRole(Request $request)
    {
        $request->validate([
            'user_id' => 'required|string',
            'role' => 'required|in:regular,admin',
        ]);
        $firestore = $this->documentStoreService;
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
