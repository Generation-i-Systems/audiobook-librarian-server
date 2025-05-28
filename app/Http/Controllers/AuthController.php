<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected $firestore;
    protected $firestoreUserProvider;

    public function __construct()
    {
        $this->firestore = new FirestoreService();
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400); // Bad Request
        }

        // Hash password here
        $password = Hash::make($request->password);

        // Check if email already exists in users or account_requests
        $existingUser = $this->firestore->getUserByCredentials(['email' => $request->email]);
        if ($existingUser) {
            return response()->json(['email' => ['Email already exists.']], 400);
        }
        $existingRequest = $this->firestore->getUserByCredentials(['email' => $request->email]);
        if ($existingRequest) {
            return response()->json(['email' => ['Account request already submitted with this email.']], 400);
        }

        $this->firestore->getClient()->collection('account_requests')->add([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $password,
            'status' => 'pending',
        ]);

        return response()->json(['message' => 'Account request submitted. Please wait for approval.'], 201); // Created
    }
}
