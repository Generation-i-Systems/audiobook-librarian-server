<?php

namespace App\Http\Controllers\Auth;

use App\Auth\FirestoreUser;
use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Google\Cloud\Core\Timestamp;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */
    use RegistersUsers;

    /**
     * Where to redirect users after registration.
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
        $this->middleware('guest');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function ($attribute, $value, $fail) {
                    $firestore = new FirestoreService();
                    $existingUser = $firestore->getClient()->collection('users')
                        ->where('email', '=', $value)
                        ->documents();

                    if (!$existingUser->isEmpty()) {
                        $fail('The email has already been taken.');
                    }
                },
            ],
            'username' => [
                'required',
                'string',
                'max:255',
                'unique:users,username',
                function ($attribute, $value, $fail) {
                    $firestore = new FirestoreService();
                    $existingUser = $firestore->getClient()->collection('users')
                        ->where('username', '=', $value)
                        ->documents();

                    if (!$existingUser->isEmpty()) {
                        $fail('The username has already been taken.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @return \App\Auth\FirestoreUser
     */
    protected function create(array $data)
    {
        try {
            $firestore = new FirestoreService();

            // Generate a unique ID for the user
            $userId = (string) Str::uuid();

            $userData = [
                'id' => $userId,
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'role' => 'unverified', // Default role for new users
                'email_verified_at' => null,
                'created_at' => new Timestamp(new \DateTime()),
                'updated_at' => new Timestamp(new \DateTime()),
            ];

            // Add the user to Firestore
            $firestore->getClient()->collection('users')->document($userId)->set($userData);

            // Notify admins about the new registration
            $this->notifyAdminsAboutNewUser($firestore, $userData);

            // Return a FirestoreUser instance for authentication
            return new FirestoreUser($userData);
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());
            throw $e; // Let the exception bubble up to be handled by Laravel
        }
    }

    /**
     * Notify admins about a new user registration
     *
     * @return void
     */
    protected function notifyAdminsAboutNewUser(FirestoreService $firestore, array $userData)
    {
        try {
            // Get all admin users
            $admins = $firestore->getClient()->collection('users')
                ->where('role', '=', 'admin')
                ->documents();

            foreach ($admins as $admin) {
                if ($admin->exists()) {
                    $messageData = [
                        'from_user_id' => $userData['id'],
                        'to_user_id' => $admin->id(),
                        'content' => sprintf(
                            'New user registered: %s (%s). Please verify their account.',
                            $userData['name'],
                            $userData['email']
                        ),
                        'is_from_admin' => false,
                        'is_read' => false,
                        'created_at' => new Timestamp(new \DateTime()),
                        'updated_at' => new Timestamp(new \DateTime()),
                    ];

                    // Add the message to Firestore
                    $firestore->getClient()->collection('messages')->add($messageData);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error notifying admins about new user: ' . $e->getMessage());
        }
    }
}
