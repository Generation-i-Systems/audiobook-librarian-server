<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Mail\AccountDeletionScheduledMail;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $activityData = $this->documentStoreService->getUserActivityData($userId);

        return view('profile.index', compact('user', 'activityData'));
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

        $admins = $this->documentStoreService->getAdminUsers();
        $adminId = $admins[0]['id'] ?? null;

        if (! is_int($adminId)) {
            return back()->with('error', 'No admin user available');
        }

        $senderId = Auth::id();

        if (! is_int($senderId)) {
            return back()->with('error', 'Unauthenticated');
        }

        $payload = [
            'type' => 'admin_permission_request',
            'content' => $request->input('content'),
        ];

        $messageId = $this->documentStoreService->createMessage([
            'sender_id' => $senderId,
            'recipient_id' => $adminId,
            'content' => json_encode($payload),
        ]);

        if ($messageId) {
            return back()->with('success', 'Admin permission request sent!');
        }

        return back()->with('error', 'Failed to send admin request. Please try again.');
    }


    /**
     * Web-based, session-authenticated account deletion. This is the "delete your account
     * without needing the app" path required by app store account-deletion policies (Amazon,
     * Google Play) — mirrors AuthController::deleteAccount()'s scheduling/email behavior, but
     * doesn't need its own OTP re-verification since the user already proved email ownership
     * via the OTP login that established this session.
     */
    public function destroy(Request $request, AccountDeletionService $accountDeletionService)
    {
        $userId = Auth::id();
        $user = $userId === null ? null : User::find($userId);

        if ($user === null) {
            return back()->with('error', 'Unable to verify your account.');
        }

        $request->validate([
            'confirm_email' => 'required|string',
        ]);

        if (strcasecmp((string) $request->input('confirm_email'), (string) $user->email) !== 0) {
            return back()->withErrors(['confirm_email' => 'Enter your account email exactly to confirm deletion.']);
        }

        $cancellationToken = $accountDeletionService->schedule($user);
        $scheduledFor = now()->addDays(AccountDeletionService::RETENTION_DAYS);

        Mail::to($user->email)->send(new AccountDeletionScheduledMail(
            cancellationUrl: url('/account-deletion/cancel/' . $cancellationToken),
            recipientName: $user->name,
            scheduledFor: $scheduledFor->toFormattedDateString(),
        ));

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/account-deletion/scheduled')->with(
            'status',
            'Your account is scheduled for deletion on ' . $scheduledFor->toFormattedDateString()
                . '. Check your email for a link to cancel if you change your mind.',
        );
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
