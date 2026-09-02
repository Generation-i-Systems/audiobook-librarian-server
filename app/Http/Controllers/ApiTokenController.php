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
     * Ability tag stamped on every token created through this self-serve UI,
     * so the profile page can list/manage only these - not every Sanctum
     * token that's ever touched the account (debug tools, internal service
     * clients, etc. also mint tokens for the same user).
     */
    public const SERVICE_TOKEN_ABILITY = 'service-token';

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
        $token = $user->createToken($request->input('name'), [self::SERVICE_TOKEN_ABILITY]);

        return back()->with('new_token', $token->plainTextToken)
            ->with('new_token_name', $request->input('name'));
    }

    /**
     * Revoke a personal access token belonging to the current user.
     */
    public function destroy(PersonalAccessToken $token): RedirectResponse
    {
        $user = User::findOrFail(Auth::id());

        $isOwnToken = $token->tokenable_id === $user->id && $token->tokenable_type === User::class;
        $isServiceToken = in_array(self::SERVICE_TOKEN_ABILITY, $token->abilities ?? [], true);

        if (!$isOwnToken || !$isServiceToken) {
            abort(403);
        }

        $token->delete();

        return back()->with('success', 'Token revoked.');
    }
}
