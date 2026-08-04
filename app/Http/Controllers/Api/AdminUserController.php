<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Mail\EmailOtpMail;
use App\Mail\WelcomeMail;
use App\Models\EmailOtp;
use App\Support\AppConnectLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminUserController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * GET /api/admin/users
     *
     * List users with optional search and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));

        $users = $this->documentStoreService->getAllUsers();

        if ($search) {
            $search = strtolower($search);
            $users = array_values(array_filter($users, function (array $u) use ($search): bool {
                return str_contains(strtolower((string) ($u['name'] ?? '')), $search)
                    || str_contains(strtolower((string) ($u['email'] ?? '')), $search)
                    || str_contains(strtolower((string) ($u['username'] ?? '')), $search);
            }));
        }

        $total = count($users);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($users, $offset, $perPage);

        return response()->json([
            'users' => array_map([$this, 'formatUser'], $paged),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * POST /api/admin/users
     *
     * Create a new user and send an OTP welcome email.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'username' => 'required|string|max:255|alpha_dash',
            'role' => 'sometimes|string|in:user,admin,unverified',
            'send_otp_email' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $username = strtolower(trim((string) $request->input('username')));

        if ($this->documentStoreService->userExistsByEmail($email)) {
            return response()->json(['message' => 'A user with that email already exists.'], 422);
        }

        if ($this->documentStoreService->userExistsByUsername($username)) {
            return response()->json(['message' => 'A user with that username already exists.'], 422);
        }

        $userId = $this->documentStoreService->createUser([
            'name' => $request->input('name'),
            'email' => $email,
            'username' => $username,
            'password' => Hash::make(Str::random(32)),
            'role' => $request->input('role', 'user'),
            'must_change_password' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!$userId) {
            return response()->json(['message' => 'Failed to create user.'], 500);
        }

        $sendOtp = (bool) $request->input('send_otp_email', true);
        if ($sendOtp) {
            $this->sendWelcomeEmailToUser($request, $email, $request->input('name'));
        }

        $user = $this->documentStoreService->getUserById($userId);

        return response()->json([
            'user' => $this->formatUser((array) $user),
            'message' => $sendOtp ? 'User created and sign-in email sent.' : 'User created successfully.',
        ], 201);
    }

    /**
     * POST /api/admin/users/{id}/send-otp
     *
     * Send an OTP login email to an existing user.
     */
    public function sendOtp(Request $request, string $id): JsonResponse
    {
        $user = $this->documentStoreService->getUserById($id);
        if ($user === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $email = (string) ($user['email'] ?? '');
        if (!$email) {
            return response()->json(['message' => 'User has no email address.'], 422);
        }

        $this->sendOtpEmailToUser($email, $user['name'] ?? null);

        return response()->json(['message' => 'Sign-in email sent to ' . $email . '.']);
    }

    /**
     * POST /api/admin/users/{id}/verify
     *
     * Approve a pending (role=unverified) self-service signup, assigning it
     * a real role and sending the welcome email as its first usable login.
     */
    public function verify(Request $request, string $id): JsonResponse
    {
        $user = $this->documentStoreService->getUserById($id);
        if ($user === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (($user['role'] ?? '') !== 'unverified') {
            return response()->json(['message' => 'User is already verified.']);
        }

        $role = $request->input('role', 'user');
        if (!in_array($role, ['user', 'library-user', 'librivox-user', 'hybrid-user', 'admin', 'super-admin'], true)) {
            return response()->json(['message' => 'Invalid role selected.'], 422);
        }

        $this->documentStoreService->updateUser($id, [
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        $email = (string) ($user['email'] ?? '');
        if ($email) {
            $this->sendWelcomeEmailToUser($request, $email, $user['name'] ?? null);
        }

        $updatedUser = $this->documentStoreService->getUserById($id);

        return response()->json([
            'user' => $this->formatUser((array) $updatedUser),
            'message' => 'User verified successfully.',
        ]);
    }

    /**
     * POST /api/admin/users/{id}/login-qr
     *
     * Mint a login OTP for an existing user without emailing it, so the
     * admin can display it as a scannable QR code instead.
     */
    public function generateLoginQr(Request $request, string $id): JsonResponse
    {
        $user = $this->documentStoreService->getUserById($id);
        if ($user === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $email = (string) ($user['email'] ?? '');
        if (!$email) {
            return response()->json(['message' => 'User has no email address.'], 422);
        }

        $otp = $this->createLoginOtp($email);

        return response()->json([
            'url' => url('/auth/magic/' . $otp['token']),
            'expires_in_seconds' => EmailOtp::TTL_MINUTES * 60,
        ]);
    }

    /**
     * Create and persist a login OTP record for the given email, returning
     * the plaintext code and magic token before they're hashed into storage.
     *
     * @return array{code: string, token: string}
     */
    private function createLoginOtp(string $email): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $magicToken = bin2hex(random_bytes(32));

        EmailOtp::where('email', $email)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        EmailOtp::create([
            'email' => $email,
            'code_hash' => hash('sha256', $code),
            'magic_token_hash' => hash('sha256', $magicToken),
            'allow_signup' => false,
            'type' => EmailOtpController::TYPE_LOGIN,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(EmailOtp::TTL_MINUTES),
        ]);

        return ['code' => $code, 'token' => $magicToken];
    }

    private function sendOtpEmailToUser(string $email, ?string $name): void
    {
        $otp = $this->createLoginOtp($email);

        try {
            Mail::to($email)->send(new EmailOtpMail(
                code: $otp['code'],
                magicLinkUrl: url('/auth/magic/' . $otp['token']),
                ttlMinutes: EmailOtp::TTL_MINUTES,
                recipientName: $name,
            ));
        } catch (\Throwable $e) {
            Log::error('AdminUserController: failed to send OTP mail', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendWelcomeEmailToUser(Request $request, string $email, ?string $name): void
    {
        $otp = $this->createLoginOtp($email);
        $apiUrl = AppConnectLinks::apiBaseUrl($request);

        try {
            Mail::to($email)->send(new WelcomeMail(
                code: $otp['code'],
                magicLinkUrl: url('/auth/magic/' . $otp['token']),
                ttlMinutes: EmailOtp::TTL_MINUTES,
                recipientName: $name,
                connectUrl: AppConnectLinks::redirectorUrl($request, $apiUrl),
                androidStoreUrl: (string) config('app.mobile_android_store_url', 'https://play.google.com/store/apps/details?id=com.ablibrarian.library'),
                iosStoreUrl: (string) config('app.mobile_ios_store_url', '#'),
            ));
        } catch (\Throwable $e) {
            Log::error('AdminUserController: failed to send welcome mail', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatUser(array $user): array
    {
        return [
            'id' => (string) ($user['id'] ?? ''),
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'username' => $user['username'] ?? null,
            'role' => $user['role'] ?? null,
            'must_change_password' => (bool) ($user['must_change_password'] ?? false),
            'created_at' => isset($user['created_at']) ? (string) $user['created_at'] : null,
        ];
    }
}
