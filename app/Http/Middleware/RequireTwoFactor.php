<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        // Als 2FA ingesteld maar nog niet bevestigd voor deze sessie
        if ($user->two_factor_secret && ! $request->session()->get('two_factor_verified')) {
            // Sla het gewenste doel op en redirect naar 2FA verificatie
            if (! $request->is('admin/two-factor*')) {
                $request->session()->put('two_factor_redirect', $request->url());
                return redirect()->route('filament.admin.pages.two-factor-verify');
            }
        }

        return $next($request);
    }
}
