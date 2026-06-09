<?php

namespace App\Http\Middleware;

use App\Models\BlockedIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBlockedIp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (BlockedIp::isBlocked($request->ip())) {
            abort(403, 'Uw IP-adres is tijdelijk geblokkeerd vanwege verdachte activiteit.');
        }

        return $next($request);
    }
}
