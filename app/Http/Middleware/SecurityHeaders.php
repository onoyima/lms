<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response (Target 86, 204): a strict Content Security
 * Policy with per-request nonces, no framing (stops click-jacking of the exam page),
 * camera/microphone/screen only for this site, HSTS when served over HTTPS.
 */
class SecurityHeaders
{
    /** The platform's own media server (LiveKit) — its WebSocket and HTTP origins only. */
    private function mediaOrigins(): string
    {
        $url = (string) config('platform.livekit.url');
        $p = parse_url($url);
        if (! $url || empty($p['host']) || ! in_array($p['scheme'] ?? '', ['ws', 'wss'], true)) {
            return '';
        }
        $host = $p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        $http = $p['scheme'] === 'wss' ? 'https' : 'http';

        return " {$p['scheme']}://$host $http://$host";
    }

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $dev = is_file(public_path('hot')); // Vite dev server running locally
        $connect = "'self'".($dev ? ' ws: http://localhost:* http://127.0.0.1:* http://[::1]:*' : '').$this->mediaOrigins();
        $script = "'self' 'nonce-{$nonce}'".($dev ? ' http://localhost:* http://127.0.0.1:* http://[::1]:*' : '');

        $headers = [
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "script-src $script",
                "style-src 'self' 'unsafe-inline'".($dev ? ' http://localhost:* http://127.0.0.1:* http://[::1]:*' : ''), // MathJax and React set inline styles
                "img-src 'self' data: blob:",
                "media-src 'self' blob:",
                "font-src 'self' data:",
                "connect-src $connect",
                "frame-ancestors 'none'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]),
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(self), display-capture=(self), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $k => $v) {
            if (! $response->headers->has($k)) {
                $response->headers->set($k, $v);
            }
        }
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
