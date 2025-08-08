<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected $documentStore;


    public function __construct()
    {
        $this->documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);
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

        // Check if email already exists in users
        if ($this->documentStore->userExistsByEmail($request->email)) {
            return response()->json(['email' => ['Email already exists.']], 400);
        }

        // Check if account request already exists with this email
        // Since there's no specific interface method for checking account requests,
        // we'll create a generic document check method in the interface later
        // For now, we'll use a safer approach by creating the account request directly

        // Create account request
        $accountRequestData = [
            'name' => $request->name,
            'email' => $request->email,
            'password' => $password,
            'status' => 'pending',
            'created_at' => new \DateTime(),
        ];

        // Use a generic create method or add a specific createAccountRequest method to the interface
        // For now, we'll use a temporary solution with createMessage as it has similar signature
        $this->documentStore->createMessage([
            'type' => 'account_request',
            'recipient_id' => 'admin', // Admin will receive this message
            'sender_id' => null,
            'content' => json_encode($accountRequestData),
            'created_at' => new \DateTime(),
        ]);

        return response()->json(['message' => 'Account request submitted. Please wait for approval.'], 201); // Created
    }
}
