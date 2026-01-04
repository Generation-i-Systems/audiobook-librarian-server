<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        Password::broker('users')->sendResetLink([
            'email' => $request->input('email'),
        ]);

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ], 200);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $status = Password::broker('users')->reset(
            [
                'email' => $request->input('email'),
                'token' => $request->input('token'),
                'password' => $request->input('password'),
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function ($user) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make((string) $request->input('password')),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Invalid token or email.',
            ], 400);
        }

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ], 200);
    }
}
