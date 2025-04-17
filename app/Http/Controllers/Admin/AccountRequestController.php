<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountRequestController extends Controller
{
    public function index()
    {
        $accountRequests = AccountRequest::where('status', 'pending')->get();
        return view('admin.account_requests.index', compact('accountRequests'));
    }

    public function approve(AccountRequest $accountRequest)
    {
        // Create a new user
        User::create([
            'name' => $accountRequest->name,
            'email' => $accountRequest->email,
            'password' => $accountRequest->password, // Password already hashed
        ]);

        // Update request to approved
        $accountRequest->status = 'approved';
        $accountRequest->save();

        return back()->with('success', 'Account request approved!');
    }

    public function reject(AccountRequest $accountRequest)
    {
        $accountRequest->status = 'rejected';
        $accountRequest->save();

        return back()->with('success', 'Account request rejected!');
    }
}
