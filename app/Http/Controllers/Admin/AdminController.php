<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function updateRole(Request $request, User $user)
    {
        $request->validate([
            'role' => 'required|in:regular,admin',
        ]);

        $user->role = $request->input('role');
        $user->save();

        return back()->with('success', 'User role updated successfully!');
    }

    public function index()
    {
        return view('admin.index');
    }

}
