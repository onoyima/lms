<?php

namespace App\Bridges\Vuna\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The bridge answers only when switched on, and only with a database on this computer. */
class EnsureBridgeReady
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('vuna_bridge.enabled'), 404);
        if ($reason = config('vuna_bridge.blocked')) {
            return response()->json(['error' => 'temporarily_unavailable', 'error_description' => $reason], 503);
        }

        return $next($request);
    }
}
