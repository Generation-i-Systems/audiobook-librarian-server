<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Services\NewUserRegistrationNotifier;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
     */
    protected NewUserRegistrationNotifier $registrationNotifier;

    public function __construct(NewUserRegistrationNotifier $registrationNotifier)
    {
        $this->middleware('guest');
        $this->registrationNotifier = $registrationNotifier;
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
                function ($attribute, $value, $fail): void {
                    $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);

                    if ($documentStore->userExistsByEmail($value)) {
                        $fail('The email has already been taken.');
                    }
                },
            ],
            'username' => [
                'required',
                'string',
                'max:255',
                'unique:users,username',
                function ($attribute, $value, $fail): void {
                    $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);

                    if ($documentStore->userExistsByUsername($value)) {
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
     * @return \App\Auth\DocumentstoreUser
     */
    protected function create(array $data)
    {
        try {
            $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);

            $userData = [
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'role' => 'unverified', // Default role for new users
                'email_verified_at' => null,
            ];

            // Create the user through the document store service
            $userId = $documentStore->createUser($userData);

            // Get the complete user data
            $completeUserData = $documentStore->getUserById($userId);

            // Notify admins about the new registration
            $this->notifyAdminsAboutNewUser($documentStore, $completeUserData);

            // Send external email notification about the new registration
            $this->registrationNotifier->send($completeUserData, 'web', request());

            // Return a DocumentstoreUser instance for authentication
            return new DocumentstoreUser($completeUserData);
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());

            throw $e; // Let the exception bubble up to be handled by Laravel
        }
    }

    /**
     * Notify admins about a new user registration.
     */
    protected function notifyAdminsAboutNewUser(DocumentStoreServiceInterface $documentStore, array $userData): void
    {
        try {
            // Get all admin users
            $admins = $documentStore->getAdminUsers();

            foreach ($admins as $admin) {
                $messageData = [
                    'sender_id' => $userData['id'],
                    'recipient_id' => $admin['id'],
                    'content' => sprintf(
                        'New user registered: %s (%s). Please verify their account.',
                        $userData['name'],
                        $userData['email']
                    ),
                ];

                // Create the message through the document store service
                $documentStore->createMessage($messageData);
            }
        } catch (\Exception $e) {
            Log::error('Error notifying admins about new user: ' . $e->getMessage());
        }
    }
}
