<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;

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
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return array
     */
    protected function create(array $data)
    {
        $firestore = new \App\Services\FirestoreService();
        // Check for existing username/email (Firestore doesn't have unique validation)
        try {
            $existingUsers = $firestore->getUserByCredentials(['username' => $data['username']]);
            if ($existingUsers) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'username' => ['Username already exists.'],
                ]);
            }
            $existingEmails = $firestore->getUserByCredentials(['email' => $data['email']]);
            if ($existingEmails) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => ['Email already exists.'],
                ]);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('Firestore user existence check failed: ' . $e->getMessage());
            throw new \Exception('Could not validate user uniqueness: ' . $e->getMessage());
        }

        // Hash the password before storing
        $data['password'] = \Illuminate\Support\Facades\Hash::make($data['password']);
        // Optionally set other fields, e.g. created_at
        $data['created_at'] = now();
        $userId = $firestore->createUser($data);
        if (!$userId) {
            throw new \Exception('Failed to create user in Firestore.');
        }
        $data['id'] = $userId;
        return new \App\Auth\FirestoreUser($data);
    }
}
