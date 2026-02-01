<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthApiController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    /**
     * Authenticate with Google OAuth
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function googleAuth(Request $request)
    {
        $request->validate([
            'id_token' => 'required_without:access_token',
            'access_token' => 'required_without:id_token',
        ]);

        try {
            // Get user info from Google
            $googleUser = null;

            try {
                if ($request->has('id_token')) {
                    // Direct validation with Google's OAuth2 service
                    $client = new \Google_Client(['client_id' => config('services.google.client_id')]);
                    $payload = $client->verifyIdToken($request->input('id_token'));

                    if ($payload) {
                        $googleUser = new \Laravel\Socialite\Two\User();
                        $googleUser->id = $payload['sub'];
                        $googleUser->name = $payload['name'] ?? null;
                        $googleUser->email = $payload['email'] ?? null;
                        $googleUser->avatar = $payload['picture'] ?? null;
                    }
                } else {
                    // Use standard Socialite for access_token
                    /** @var \Laravel\Socialite\Contracts\Provider $driver */
                    $driver = Socialite::driver('google');
                    // @phpstan-ignore-next-line
                    $googleUser = $driver->stateless()
                        ->userFromToken($request->input('access_token'));
                }
            } catch (\Exception $e) {
                Log::error('Google token validation error: ' . $e->getMessage());
                // Continue with null googleUser, will be caught in next check
            }

            if (!$googleUser || !$googleUser->getEmail()) {
                Log::error('Failed to get user info from Google', [
                    'googleUser' => $googleUser,
                ]);

                return response()->json(['error' => 'Invalid Google credentials'], 400);
            }

            // Find or create user
            $isNewUser = false;
            $user = $this->documentStoreService->getUserByCredentials([
                'email' => $googleUser->getEmail(),
            ]);

            if (!$user) {
                // Create new user
                $isNewUser = true;
                $userData = [
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'password' => bcrypt(Str::random(16)),
                    'email_verified_at' => now(),
                    'avatar' => $googleUser->getAvatar(),
                ];
                $userId = $this->documentStoreService->createUser($userData);
                $user = $this->documentStoreService->getUserById($userId);
            } else {
                // Update existing user's Google ID if not set
                if (!isset($user['google_id'])) {
                    $this->documentStoreService->updateUser($user['id'], [
                        'google_id' => $googleUser->getId(),
                        'avatar' => $googleUser->getAvatar(),
                    ]);
                }
            }

            // Generate token using Laravel's built-in JWT functionality
            /** @phpstan-ignore-next-line class.notFound, varTag.nativeType */
            $guard = auth('api');
            /** @phpstan-ignore-next-line class.notFound */
            $token = $guard->fromUser((object) $user);

            return response()->json([
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'avatar' => $user['avatar'] ?? null,
                ],
                'is_new_user' => $isNewUser,
            ]);
        } catch (Exception $e) {
            Log::error('Google OAuth error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Authentication failed: ' . $e->getMessage()], 400);
        }
    }
}
