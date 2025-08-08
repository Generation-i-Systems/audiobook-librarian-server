<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    public function index()
    {
        $userId = Auth::id();
        $user = $this->documentStoreService->getUserById($userId);

        if ($user) {
            $user['id'] = $userId;
        } else {
            $user = ['id' => $userId, 'name' => 'Guest User'];
            Log::warning("User {$userId} not found in document store");
        }

        return view('profile.index', compact('user'));
    }


    public function update(Request $request)
    {
        $userId = Auth::id();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function ($attribute, $value, $fail) use ($userId) {
                    $existingUser = $this->documentStoreService->getUserByEmail($value);
                    if ($existingUser && $existingUser['id'] !== $userId) {
                        $fail('The email has already been taken.');
                    }
                },
            ],
        ]);

        $success = $this->documentStoreService->updateUser($userId, [
            'name' => $request->name,
            'email' => $request->email,
        ]);

        if ($success) {
            return back()->with('success', 'Profile updated successfully!');
        }

        return back()->with('error', 'Failed to update profile. Please try again.');
    }


    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $userId = Auth::id();
        $user = $this->documentStoreService->getUserById($userId);

        if (!$user || !Hash::check($request->current_password, $user['password'] ?? '')) {
            return back()->withErrors(['current_password' => 'Incorrect current password.']);
        }

        $success = $this->documentStoreService->updateUser($userId, [
            'password' => Hash::make($request->password),
        ]);

        if ($success) {
            return back()->with('success', 'Password changed successfully!');
        }

        return back()->with('error', 'Failed to update password. Please try again.');
    }


    public function requestAdminPermissions(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
        ]);

        $userId = Auth::id();

        $messageId = $this->documentStoreService->createMessage([
            'user_id' => $userId,
            'content' => $request->input('content'),
            'is_from_admin' => false,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        if ($messageId) {
            return back()->with('success', 'Admin permission request sent!');
        }

        return back()->with('error', 'Failed to send admin request. Please try again.');
    }


    /**
     * Normalize document data to array
     *
     * @param  mixed  $document
     */
    protected function normalizeDocument($document): array
    {
        if (is_array($document)) {
            return $document;
        }

        if (is_object($document) && method_exists($document, 'toArray')) {
            return $document->toArray();
        }

        return (array) $document;
    }
}
