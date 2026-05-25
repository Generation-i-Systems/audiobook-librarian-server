<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Services\NewUserRegistrationNotifier;
use App\Support\MailConfiguration;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */
    use AuthenticatesUsers;

    protected DocumentStoreServiceInterface $documentStoreService;

    protected NewUserRegistrationNotifier $registrationNotifier;

    public function __construct(DocumentStoreServiceInterface $documentStoreService, NewUserRegistrationNotifier $registrationNotifier)
    {
        $this->documentStoreService = $documentStoreService;
        $this->registrationNotifier = $registrationNotifier;
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /**
     * Show the login form.
     */
    public function showLoginForm()
    {
        return view('auth.login', [
            'otpEnabled' => MailConfiguration::isMailConfigured(),
        ]);
    }

    /**
     * Allow login with either email or username.
     */
    public function username()
    {
        return 'login';
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * @return array
     */
    protected function credentials(\Illuminate\Http\Request $request)
    {
        $login = $request->input('login');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [
            $field => $login,
            'password' => $request->input('password'),
        ];
    }

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @param mixed $user
     */

    /**
     * Called after user is authenticated.
     *
     * @param mixed $user
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    protected function authenticated(\Illuminate\Http\Request $request, $user)
    {
        // Update last login timestamp
        try {
            $this->documentStoreService->updateUser((string) $user->getAuthIdentifier(), [
                'last_login_at' => new \DateTime(),
                'updated_at' => new \DateTime(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating last login time: ' . $e->getMessage());
        }

        return redirect()->intended($this->redirectPath());
    }

    private function googleRedirectUri(): string
    {
        return request()->getSchemeAndHttpHost() . '/login/google/callback';
    }

    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle()
    {
        config(['services.google.redirect' => $this->googleRedirectUri()]);

        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handleGoogleCallback()
    {
        try {
            config(['services.google.redirect' => $this->googleRedirectUri()]);
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            Log::error('Google login error: ' . $e->getMessage());

            return redirect('/login')->withErrors(['google' => 'Unable to login with Google. Please try again.']);
        }

        if (! $googleUser->getEmail()) {
            return redirect('/login')->withErrors(['google' => 'No email provided by Google.']);
        }

        try {
            // Check if user exists
            $existingUserArr = $this->documentStoreService->getUserByEmail($googleUser->getEmail());
            $userId = null;
            $isNewUser = false;

            // Create or update user
            if (! $existingUserArr) {
                // Create new unverified user treated as a registration
                $baseName = $googleUser->getName() ?? $googleUser->getNickname() ?? explode('@', $googleUser->getEmail())[0];
                $username = explode('@', $googleUser->getEmail())[0];

                $newUserData = [
                    'name' => $baseName,
                    'username' => $username,
                    'email' => $googleUser->getEmail(),
                    'password' => bcrypt(Str::random(32)),
                    'role' => 'unverified',
                    'email_verified_at' => null,
                    'created_at' => new \DateTime(),
                    'updated_at' => new \DateTime(),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                ];
                $userId = $this->documentStoreService->createUser($newUserData);
                $isNewUser = true;
                Log::info('New user created via Google login', ['user_id' => $userId, 'email' => $newUserData['email']]);
            } else {
                // Update existing user
                $userId = $existingUserArr['id'];
                $updateData = [
                    'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? explode('@', $googleUser->getEmail())[0],
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                    'updated_at' => new \DateTime(),
                ];
                $this->documentStoreService->updateUser((string) $userId, $updateData);
                Log::info('Existing user updated via Google login', ['user_id' => $userId]);
            }

            if (! $userId) {
                throw new \Exception('Failed to get user ID after create/update.');
            }

            // Fetch the canonical user data from the store to ensure the session is fresh
            $userArr = $this->documentStoreService->getUserByEmail($googleUser->getEmail());

            if (empty($userArr)) {
                throw new \Exception('Failed to retrieve user account after create/update.');
            }

            if ($isNewUser) {
                $this->registrationNotifier->send((array) $userArr, 'web-google', request());
            }

            Log::debug('User array from DB before creating DocumentstoreUser', ['userArr' => $userArr]);

            // Create user object and log in
            $user = new DocumentstoreUser((array) $userArr);
            Log::debug('DocumentstoreUser object created', ['user_object_data' => $user->getRawUser()]);

            Auth::login($user, true);

            Log::debug('Auth state immediately after login', [
                'auth_check' => Auth::check(),
                'auth_user_data' => Auth::user() ? Auth::user()->getRawUser() : null,
            ]);

            Log::info('User logged in via Google', [
                'user_id' => $user->getAuthIdentifier(),
                'email' => $user->email,
            ]);

            // Redirect to intended URL or home
            return redirect()->intended($this->redirectPath());
        } catch (\Exception $e) {
            Log::error('Error during Google authentication: ' . $e->getMessage(), [
                'exception' => $e,
                'email' => $googleUser->getEmail(),
            ]);

            return redirect('/login')
                ->withErrors([
                    'google' => 'An error occurred during authentication. Please try again or contact support.',
                ])
            ;
        }
    }

    /**
     * Log the user out of the application.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(\Illuminate\Http\Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return $this->loggedOut($request) ?: redirect('/');
    }
}
