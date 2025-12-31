<?php

namespace App\Services;

use Kreait\Firebase\Auth\Token\Exception\InvalidToken;
use Kreait\Firebase\Factory;

class FirebaseAuthService
{
    protected $auth;

    public function __construct()
    {
        $factory = (new Factory())->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')));
        $this->auth = $factory->createAuth();
    }

    /**
     * Verifies the Firebase ID token and returns the UID if valid, or null if invalid.
     *
     * @param  string  $idToken
     * @return string|null
     */
    public function verifyIdToken($idToken)
    {
        try {
            $verifiedIdToken = $this->auth->verifyIdToken($idToken);

            return $verifiedIdToken->claims()->get('sub'); // Firebase UID
        } catch (InvalidToken $e) {
            return null;
        }
    }

    /**
     * Returns the Firebase user record by UID.
     *
     * @param  string  $uid
     * @return \Kreait\Firebase\Auth\UserRecord|null
     */
    public function getUser($uid)
    {
        try {
            return $this->auth->getUser($uid);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
