<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;

use Laravel\Socialite\Facades\Socialite;
use App\Auth\FirestoreUser;
use Illuminate\Support\Facades\Auth;
use App\Services\FirestoreService;
use Illuminate\Support\Facades\Log;

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
     * @param  \Illuminate\Http\Request  $request
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
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }
    /**
     * Called after user is authenticated.
     */
    protected function authenticated(\Illuminate\Http\Request $request, $user)
    {
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
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return redirect('/login')->withErrors(['google' => 'Unable to login with Google.']);
        }

        $firestore = new FirestoreService();
        $userData = $firestore->getClient()->collection('users')
            ->where('email', '=', $googleUser->getEmail())
            ->documents();

        $userArr = null;
        foreach ($userData as $doc) {
            if ($doc->exists()) {
                $userArr = $doc->data();
                $userArr['id'] = $doc->id();
                break;
            }
        }

        if (!$userArr) {
            // Create new user
            $newUserData = [
                'name' => $googleUser->getName() ?? $googleUser->getNickname(),
                'email' => $googleUser->getEmail(),
                'password' => bcrypt(str()->random(16)), // random password, not used
                'created_at' => now(),
                'role' => 'user',
            ];
            $docRef = $firestore->getClient()->collection('users')->add($newUserData);
            $userArr = $docRef->snapshot()->data();
            $userArr['id'] = $docRef->id();
        }

        if (!$userArr) {
            return redirect('/login')->withErrors(['google' => 'Unable to create or retrieve your account. Please try again or contact support.']);
        }

        $user = new FirestoreUser($userArr);
        Auth::login($user, true);

        Log::debug('Google login', [
            'auth_check' => Auth::check(),
            'user_id' => Auth::id(),
            'user_class' => get_class(Auth::user()),
            'session_id' => session()->getId(),
            'session_data' => session()->all(),
        ]);

        return redirect('/home');
    }
}
