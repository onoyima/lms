<?php

namespace App\Http\Middleware;

use App\Models\Attempt;
use App\Models\SecurityEvent;
use App\Services\Exams\AttemptToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exam API authentication: "Authorization: Bearer <attempt token>". Stateless (no session
 * reads/writes on the hot path). Only the newest token works: if the exam was opened in
 * another window or device, this one gets 409 session_moved (Target 27, 75, 87, 130).
 */
class AuthenticateAttempt
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $id = $token !== '' ? AttemptToken::attemptId($token) : null;
        $attempt = $id ? Attempt::with(['exam', 'session'])->find($id) : null;

        if (! $attempt) {
            $this->log($request, 'token_rejected', ['reason' => 'invalid']);

            return response()->json(['message' => 'Your exam session is not valid. Open the exam again.', 'reason' => 'invalid_token'], 401);
        }
        if (! AttemptToken::matches($attempt, $token)) {
            $this->log($request, 'token_rejected', ['reason' => 'superseded', 'attempt' => $attempt->id], $attempt->user_id);

            return response()->json(['message' => 'This exam was opened in another window or device. Only the newest one can continue.', 'reason' => 'session_moved'], 409);
        }

        // an attempt started in the desktop app stays on that approved computer: every call must be signed by it
        if ($attempt->channel === 'desktop') {
            $device = app(\App\Services\Exams\ChannelVerifier::class)->verifyDesktop($request, allowUnsignedBody: $request->isMethod('post') && str_contains((string) $request->header('Content-Type'), 'multipart/'));
            if (! $device || ($attempt->device_hash && ! hash_equals($attempt->device_hash, hash('sha256', (string) $request->header('X-Platform-Device'))))) {
                $this->log($request, 'token_rejected', ['reason' => 'desktop_signature', 'attempt' => $attempt->id], $attempt->user_id);

                return response()->json(['message' => 'This exam must continue in the desktop exam app on the same computer.', 'reason' => 'wrong_device'], 403);
            }
        }

        $request->attributes->set('attempt', $attempt);

        return $next($request);
    }

    private function log(Request $request, string $type, array $data, ?int $userId = null): void
    {
        SecurityEvent::create([
            'tenant_id' => tenant_id(), 'user_id' => $userId, 'type' => $type, 'ip' => $request->ip(),
            'path' => substr($request->path(), 0, 255), 'data' => $data, 'created_at' => now(),
        ]);
    }
}
