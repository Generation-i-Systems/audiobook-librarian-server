<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountRequestController extends Controller
{
    public function index()
    {
        $firestore = new FirestoreService();
        $accountRequests = $firestore->db->collection('account_requests')->where('status', '=', 'pending')->documents();
        $requests = [];
        foreach ($accountRequests as $doc) {
            if ($doc->exists()) {
                $req = $doc->data();
                $req['id'] = $doc->id();
                $requests[] = $req;
            }
        }
        return view('admin.account_requests.index', ['accountRequests' => $requests]);
    }

    public function approve($id)
    {
        $firestore = new FirestoreService();
        $accountRequestDoc = $firestore->db->collection('account_requests')->document($id)->snapshot();
        if (!$accountRequestDoc->exists()) {
            return back()->withErrors(['error' => 'Account request not found.']);
        }
        $accountRequest = $accountRequestDoc->data();
        // Create a new user
        $firestore->db->collection('users')->add([
            'name' => $accountRequest['name'],
            'email' => $accountRequest['email'],
            'password' => $accountRequest['password'], // Password already hashed
            'role' => 'user',
        ]);
        // Update request to approved
        $firestore->db->collection('account_requests')->document($id)->set([
            'status' => 'approved'
        ], ['merge' => true]);
        return back()->with('success', 'Account request approved!');
    }

    public function reject($id)
    {
        $firestore = new FirestoreService();
        $firestore->db->collection('account_requests')->document($id)->set([
            'status' => 'rejected'
        ], ['merge' => true]);
        return back()->with('success', 'Account request rejected!');
    }
}
