<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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
        $userId = $request->input('user_id');
        $role = $request->input('role');
        $this->documentStoreService->updateUser($userId, ['role' => $role]);

        return back()->with('success', 'User role updated successfully!');
    }


    public function index()
    {
        return view('admin.index');
    }
}
