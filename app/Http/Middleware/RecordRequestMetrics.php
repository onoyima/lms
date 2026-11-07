<?php

namespace App\Http\Middleware;

use App\Services\Monitoring\Metrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Counts requests, errors and response times per minute for the built-in monitoring (LMS 140, 143, 243). */
class RecordRequestMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /** After the response is sent, so it never slows a candidate down. */
    public function terminate(Request $request, Response $response): void
    {
        if ($request->is('up', 'api/health')) {
            return;
        }
        $ms = (microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', microtime(true)))) * 1000;
        Metrics::request($response->getStatusCode(), $ms, $request->is('api/exam/*'));
    }
}
