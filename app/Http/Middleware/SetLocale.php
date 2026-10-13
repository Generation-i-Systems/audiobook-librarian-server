<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Pick the UI language: the signed-in user's saved choice, then the session choice,
     * then the browser's Accept-Language, then the configured default. Only locales
     * listed in config/locales.php are ever applied.
     *
     * @param \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<string, string> $supported */
        $supported = (array) config('locales.supported', []);
        $codes = array_map('strval', array_keys($supported));

        // Symfony reports region locales with an underscore (pt_BR); our codes use a hyphen.
        $byUnderscore = array_combine(array_map(fn (string $c): string => str_replace('-', '_', $c), $codes), $codes);

        $user = $request->user();

        $candidates = [
            $user instanceof User ? $user->locale : null,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $byUnderscore[$request->getPreferredLanguage(array_keys($byUnderscore))] ?? null,
            config('locales.default'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $codes, true)) {
                app()->setLocale($candidate);
                break;
            }
        }

        return $next($request);
    }
}
