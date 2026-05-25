<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Mail\EmailOtpMail;
use App\Models\EmailOtp;
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
            $this->sendOtpEmailToUser($email, $request->input('name'));
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

    private function sendOtpEmailToUser(string $email, ?string $name): void
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

        try {
            Mail::to($email)->send(new EmailOtpMail(
                code: $code,
                magicLinkUrl: url('/auth/magic/' . $magicToken),
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
