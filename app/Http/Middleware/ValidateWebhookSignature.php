<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidateWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.webhook.secret');
        $signature = $request->header('X-Webhook-Signature');

        if (! $signature) {
            Log::warning('Webhook: ontbrekende signature', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Handtekening ontbreekt.'], 401);
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Webhook: ongeldige signature', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Ongeldige handtekening.'], 403);
        }

        return $next($request);
    }
}
