<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    /**
     * Create a new personal access token for the current user, e.g. for use
     * by the ABB bridge browser extension or other personal automation.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = User::findOrFail(Auth::id());
        $token = $user->createToken($request->input('name'));

        return back()->with('new_token', $token->plainTextToken)
            ->with('new_token_name', $request->input('name'));
    }

    /**
     * Revoke a personal access token belonging to the current user.
     */
    public function destroy(PersonalAccessToken $token): RedirectResponse
    {
        $user = User::findOrFail(Auth::id());

        if ($token->tokenable_id !== $user->id || $token->tokenable_type !== User::class) {
            abort(403);
        }

        $token->delete();

        return back()->with('success', 'Token revoked.');
    }
}
