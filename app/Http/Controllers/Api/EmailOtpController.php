<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Mail\EmailOtpMail;
use App\Models\EmailOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmailOtpController extends Controller
{
    public const REQUEST_THROTTLE_KEY = 'otp-request:';
    public const VERIFY_THROTTLE_KEY = 'otp-verify:';
    public const REQUEST_PER_MINUTE = 5;
    public const VERIFY_PER_MINUTE = 10;

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * POST /auth/otp/request
     *
     * Body:
     *   email          (required, string, email)
     *   allow_signup   (optional, bool) — when true, verification will create
     *                                    a pending-approval user if no user
     *                                    with this email exists yet.
     *
     * Always returns 200 with a generic message (do not leak existence of
     * accounts). Rate-limited per email + IP.
     */
    public function request(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
            'allow_signup' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $allowSignup = (bool) $request->input('allow_signup', false);

        $throttleKey = self::REQUEST_THROTTLE_KEY . sha1($email . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, self::REQUEST_PER_MINUTE)) {
            return response()->json([
                'message' => 'Too many requests. Please wait a minute and try again.',
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        // Generate code + magic token. Code is 6 digits zero-padded.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $magicToken = bin2hex(random_bytes(32));

        $codeHash = hash('sha256', $code);
        $magicTokenHash = hash('sha256', $magicToken);

        // Invalidate any outstanding active OTPs for this email so only the latest is usable.
        EmailOtp::where('email', $email)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        $otp = EmailOtp::create([
            'email' => $email,
            'code_hash' => $codeHash,
            'magic_token_hash' => $magicTokenHash,
            'allow_signup' => $allowSignup,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(EmailOtp::TTL_MINUTES),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        // Look up the user (if any) for personalisation. We do NOT require the
        // user to exist here — that is only enforced at verify time when
        // allow_signup is false.
        $user = $this->documentStoreService->getUserByEmail($email);
        $recipientName = $user['name'] ?? null;

        $magicLinkUrl = url('/auth/magic/' . $magicToken);

        try {
            Mail::to($email)->send(new EmailOtpMail(
                code: $code,
                magicLinkUrl: $magicLinkUrl,
                ttlMinutes: EmailOtp::TTL_MINUTES,
                recipientName: $recipientName,
            ));
        } catch (\Throwable $e) {
            Log::error('EmailOtp: failed to send mail', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            // Still return generic 200 to avoid leaking which addresses exist.
        }

        return response()->json([
            'message' => 'If an account is associated with that email, a sign-in code has been sent.',
            'expires_in_seconds' => EmailOtp::TTL_MINUTES * 60,
        ]);
    }

    /**
     * POST /auth/otp/verify
     *
     * Body (one of):
     *   { email, code }    — verify the typed 6-digit code
     *   { token }          — verify the magic-link token (no email required)
     *
     * On success returns the same shape as POST /auth/login.
     */
    public function verify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:token|nullable|string|email|max:255',
            'code' => 'required_without:token|nullable|string|size:6',
            'token' => 'required_without_all:email,code|nullable|string|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $throttleKey = self::VERIFY_THROTTLE_KEY . sha1(($request->input('email') ?? '') . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, self::VERIFY_PER_MINUTE)) {
            return response()->json(['message' => 'Too many attempts. Please wait a minute.'], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        $otp = $this->findActiveOtp($request);

        if ($otp === null) {
            return response()->json(['message' => 'Invalid or expired code.'], 400);
        }

        if (!$otp->isRedeemable()) {
            return response()->json(['message' => 'Code is no longer valid. Please request a new one.'], 400);
        }

        $codeMatched = $request->filled('token')
            ? hash_equals($otp->magic_token_hash, hash('sha256', (string) $request->input('token')))
            : hash_equals($otp->code_hash, hash('sha256', (string) $request->input('code')));

        if (!$codeMatched) {
            $otp->increment('attempts');
            if ($otp->attempts >= EmailOtp::MAX_ATTEMPTS) {
                return response()->json(['message' => 'Too many invalid attempts. Please request a new code.'], 400);
            }
            return response()->json(['message' => 'Invalid code.'], 400);
        }

        // Mark used to prevent replay.
        $otp->forceFill(['used_at' => now()])->save();

        return $this->issueAuthResponseForEmail($otp->email, $otp->allow_signup);
    }

    /**
     * GET /auth/magic/{token}
     *
     * Web landing page for magic-link emails. Tries the mobile deep-link first
     * (custom scheme handled by the installed app), then offers a "Continue in
     * browser" path that performs a server-side login via the same OTP record.
     */
    public function magicLanding(Request $request, string $token)
    {
        // Lightly validate so we don't render a useless page for obviously
        // malformed tokens.
        $hash = hash('sha256', $token);
        $otp = EmailOtp::where('magic_token_hash', $hash)->first();

        $valid = $otp !== null && $otp->isRedeemable();

        return response()->view('auth.magic-link', [
            'token' => $token,
            'valid' => $valid,
            'deepLink' => 'ablibrarian://auth/magic?token=' . urlencode($token),
            'continueUrl' => url('/auth/magic/' . $token . '/continue'),
        ]);
    }

    /**
     * POST /auth/magic/{token}/continue
     *
     * Web fallback: log the user into the Laravel web session using the magic
     * token and redirect to the homepage. Useful when the user clicks the link
     * on a device that does not have the mobile app installed.
     */
    public function magicContinue(Request $request, string $token)
    {
        $hash = hash('sha256', $token);
        $otp = EmailOtp::where('magic_token_hash', $hash)->first();

        if ($otp === null || !$otp->isRedeemable()) {
            return redirect('/login')->withErrors(['email' => 'That sign-in link is no longer valid. Please request a new one.']);
        }

        $userArray = $this->documentStoreService->getUserByEmail($otp->email);
        if ($userArray === null) {
            if (!$otp->allow_signup) {
                return redirect('/login')->withErrors(['email' => 'No account is associated with that email.']);
            }
            $createdId = $this->createPendingUserForEmail($otp->email);
            if (!$createdId) {
                return redirect('/login')->withErrors(['email' => 'Failed to create account. Please try again.']);
            }
            $userArray = $this->documentStoreService->getUserById($createdId);
        }

        $otp->forceFill(['used_at' => now()])->save();

        // Log in via the standard web guard if a matching Eloquent user exists.
        $userId = $userArray['id'] ?? null;
        /** @var \App\Models\User|null $eloquentUser */
        $eloquentUser = $userId === null ? null : \App\Models\User::query()->find($userId);
        if ($eloquentUser !== null) {
            \Illuminate\Support\Facades\Auth::login($eloquentUser, true);
            $request->session()->regenerate();
        }

        return redirect('/');
    }

    private function findActiveOtp(Request $request): ?EmailOtp
    {
        if ($request->filled('token')) {
            $hash = hash('sha256', (string) $request->input('token'));
            return EmailOtp::where('magic_token_hash', $hash)->first();
        }

        $email = strtolower(trim((string) $request->input('email')));
        return EmailOtp::where('email', $email)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();
    }

    private function issueAuthResponseForEmail(string $email, bool $allowSignup): JsonResponse
    {
        $user = $this->documentStoreService->getUserByEmail($email);

        if ($user === null) {
            if (!$allowSignup) {
                return response()->json(['message' => 'No account is associated with that email.'], 404);
            }
            $createdId = $this->createPendingUserForEmail($email);
            if (!$createdId) {
                return response()->json(['message' => 'Failed to create account.'], 500);
            }
            return response()->json([
                'code' => 'ACCOUNT_PENDING_APPROVAL',
                'message' => 'Account created. Waiting for admin approval.',
            ], 403);
        }

        if (($user['role'] ?? '') === 'unverified') {
            return response()->json([
                'code' => 'ACCOUNT_PENDING_APPROVAL',
                'message' => 'Account pending admin approval',
            ], 403);
        }

        $tokenValue = bin2hex(random_bytes(32));
        $this->documentStoreService->createApiToken([
            'user_id' => (string) ($user['id'] ?? ''),
            'token' => $tokenValue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => (string) ($user['id'] ?? ''),
            'name' => $user['name'] ?? null,
            'username' => $user['username'] ?? null,
            'email' => $user['email'] ?? null,
            'photo_url' => $user['photo_url'] ?? null,
            'role' => $user['role'] ?? null,
            'authToken' => $tokenValue,
            'refreshToken' => $tokenValue,
            'token' => $tokenValue,
        ]);
    }

    private function createPendingUserForEmail(string $email): ?string
    {
        $base = Str::before($email, '@') ?: 'user';
        $username = $base;
        $suffix = 0;
        while ($this->documentStoreService->userExistsByUsername($username)) {
            $suffix++;
            $username = $base . $suffix;
            if ($suffix > 50) {
                $username = $base . '_' . Str::lower(Str::random(6));
                break;
            }
        }

        $userData = [
            'name' => $base,
            'username' => $username,
            'email' => $email,
            'password' => Str::random(32),
            'role' => 'unverified',
        ];

        return $this->documentStoreService->createUser($userData);
    }
}
