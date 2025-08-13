<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserApiController extends Controller
{
    /**
     * Get the current authenticated user's profile information.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }
}
