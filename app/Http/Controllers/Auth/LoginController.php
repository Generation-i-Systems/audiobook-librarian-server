<?php

namespace App\Http\Controllers\Auth;

use App\Auth\FirestoreUser;
use App\Http\Controllers\Controller;
use App\Contracts\DocumentStoreServiceInterface;
use Google\Cloud\Core\Timestamp as GoogleTimestamp;
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

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
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
     * @return void
     */


    /**
     * Called after user is authenticated.
     *
     * @param  mixed  $user
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    protected function authenticated(\Illuminate\Http\Request $request, $user)
    {
        // Update last login timestamp
        try {
            $firestore = $this->documentStoreService;
            $firestore->getClient()->collection('users')
                ->document($user->getAuthIdentifier())
                ->update([
                    ['path' => 'last_login_at', 'value' => new GoogleTimestamp(new \DateTime())],
                    ['path' => 'updated_at', 'value' => new GoogleTimestamp(new \DateTime())],
                ]);
        } catch (\Exception $e) {
            Log::error('Error updating last login time: ' . $e->getMessage());
        }

        return redirect()->intended($this->redirectPath());
    }

    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle()
    {
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
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            Log::error('Google login error: ' . $e->getMessage());

            return redirect('/login')->withErrors(['google' => 'Unable to login with Google. Please try again.']);
        }

        if (!$googleUser->getEmail()) {
            return redirect('/login')->withErrors(['google' => 'No email provided by Google.']);
        }

        $firestore = $this->documentStoreService;

        try {
            // Check if user exists
            $userQuery = $firestore->getClient()->collection('users')
                ->where('email', '=', $googleUser->getEmail())
                ->limit(1)
                ->documents();

            $userArr = null;
            foreach ($userQuery as $doc) {
                if ($doc->exists()) {
                    $userArr = $doc->data();
                    $userArr['id'] = $doc->id();
                    break;
                }
            }

            // Create new user if not exists
            if (!$userArr) {
                $newUserData = [
                    'name' => $googleUser->getName() ?? $googleUser->getNickname() ??
                        explode('@', $googleUser->getEmail())[0],
                    'email' => $googleUser->getEmail(),
                    'password' => bcrypt(Str::random(32)), // random password, not used
                    'role' => 'user',
                    'email_verified_at' => new GoogleTimestamp(new \DateTime()),
                    'created_at' => new GoogleTimestamp(new \DateTime()),
                    'updated_at' => new GoogleTimestamp(new \DateTime()),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                ];

                $docRef = $firestore->getClient()->collection('users')->add($newUserData);
                $userArr = $newUserData;
                $userArr['id'] = $docRef->id();

                Log::info('New user created via Google login', [
                    'user_id' => $userArr['id'],
                    'email' => $userArr['email'],
                ]);
            } else {
                // Update existing user's Google ID and avatar
                $firestore->getClient()->collection('users')
                    ->document($userArr['id'])
                    ->update([
                        ['path' => 'google_id', 'value' => $googleUser->getId()],
                        ['path' => 'avatar', 'value' => $googleUser->getAvatar()],
                        ['path' => 'updated_at', 'value' => new GoogleTimestamp(new \DateTime())],
                    ]);
            }

            if (empty($userArr)) {
                throw new \Exception('Failed to create or retrieve user account.');
            }

            // Create user object and log in
            $user = new FirestoreUser($userArr);
            Auth::login($user, true);

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
                ]);
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
