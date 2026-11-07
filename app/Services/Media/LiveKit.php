<?php

namespace App\Services\Media;

use App\Models\Attempt;
use App\Models\ExamSession;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Self-hosted LiveKit media server (Target 4-7, 35-36, 111-124): short-lived access tokens
 * that allow exactly what each person needs — candidates publish their camera, microphone and
 * screen but can see no one; a candidate's phone publishes one camera; proctors watch, hidden.
 * Recordings are made by the server's Egress service; its webhooks are signature-checked.
 */
class LiveKit
{
    public function enabled(): bool
    {
        return (bool) (config('platform.livekit.url') && config('platform.livekit.key') && config('platform.livekit.secret'));
    }

    public function url(): string
    {
        return (string) config('platform.livekit.url');
    }

    public function room(ExamSession $s): string
    {
        return 'vt'.$s->tenant_id.'-s'.$s->id;
    }

    /** Candidate in an attempt: publish only, never subscribe to other candidates. */
    public function candidateToken(Attempt $a, array $sources): string
    {
        return $this->token('c-'.$a->id, $a->user?->name ?? 'Candidate', $this->room($a->session), [
            'roomJoin' => true, 'canPublish' => true, 'canSubscribe' => false, 'canPublishData' => false,
            'canPublishSources' => array_values($sources),
        ], 3 * 3600, ['attempt' => $a->id, 'role' => 'candidate']);
    }

    /** The candidate's phone as a second camera. */
    public function phoneToken(Attempt $a): string
    {
        return $this->token('c-'.$a->id.'-phone', ($a->user?->name ?? 'Candidate').' (phone)', $this->room($a->session), [
            'roomJoin' => true, 'canPublish' => true, 'canSubscribe' => false, 'canPublishData' => false, 'canPublishSources' => ['camera'],
        ], 3 * 3600, ['attempt' => $a->id, 'role' => 'phone']);
    }

    /** Proctors watch without being seen; they may speak to the room only when talk is allowed. */
    public function proctorToken(ExamSession $s, int $userId, string $name): string
    {
        return $this->token('p-'.$userId, $name, $this->room($s), [
            'roomJoin' => true, 'canPublish' => false, 'canSubscribe' => true, 'canPublishData' => false, 'hidden' => true,
        ], 8 * 3600, ['role' => 'proctor']);
    }

    /** HS256 JWT in LiveKit's access-token format. */
    public function token(string $identity, string $name, string $room, array $grants, int $ttl, array $metadata = []): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Live video is not set up on this platform.');
        }
        $now = time();
        $claims = ['iss' => config('platform.livekit.key'), 'sub' => $identity, 'name' => mb_substr($name, 0, 80), 'nbf' => $now - 5, 'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)), 'video' => ['room' => $room] + $grants, 'metadata' => json_encode($metadata)];

        return self::sign($claims, (string) config('platform.livekit.secret'));
    }

    public static function sign(array $claims, string $secret): string
    {
        $b = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b(json_encode($claims, JSON_UNESCAPED_SLASHES));

        return "$head.$body.".$b(hash_hmac('sha256', "$head.$body", $secret, true));
    }

    /** Webhooks carry a JWT signed with our secret whose sha256 claim is the body's hash. */
    public function verifyWebhook(string $body, ?string $auth): bool
    {
        if (! $this->enabled() || ! $auth) {
            return false;
        }
        $jwt = preg_replace('/^Bearer\s+/i', '', $auth);
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        [$h, $p, $sig] = $parts;
        $header = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", (string) config('platform.livekit.secret'), true)), '+/', '-_'), '=');
        if (($header['alg'] ?? '') !== 'HS256' || ! hash_equals($expected, $sig)) {
            return false;
        }
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true) ?: [];

        return ($claims['iss'] ?? null) === config('platform.livekit.key')
            && ($claims['exp'] ?? 0) >= time() - 60
            && hash_equals((string) ($claims['sha256'] ?? ''), base64_encode(hash('sha256', $body, true)));
    }

    /** Record one participant's camera or screen to a file on the media server. */
    public function startRecording(Attempt $a, string $kind): ?string
    {
        $identity = 'c-'.$a->id.($kind === 'phone' ? '-phone' : '');
        $file = trim((string) config('platform.livekit.recordings_path'), '/').'/t'.$a->tenant_id.'/a'.$a->id.'/'.$kind.'-{time}.mp4';
        $res = $this->twirp('StartParticipantEgress', [
            'room_name' => $this->room($a->session), 'identity' => $identity,
            'screen_share' => $kind === 'screen', 'preset' => 'H264_720P_30',
            'file_outputs' => [['filepath' => '/'.$file, 'file_type' => 'MP4']],
        ]);

        return $res['egress_id'] ?? null;
    }

    public function stopRecording(string $egressId): void
    {
        $this->twirp('StopEgress', ['egress_id' => $egressId]);
    }

    private function twirp(string $method, array $body): array
    {
        $base = rtrim((string) (config('platform.livekit.api_url') ?: preg_replace('#^ws#', 'http', $this->url())), '/');
        $token = self::sign(['iss' => config('platform.livekit.key'), 'sub' => 'platform-server', 'nbf' => time() - 5, 'exp' => time() + 60,
            'video' => ['roomRecord' => true, 'roomAdmin' => true]], (string) config('platform.livekit.secret'));

        return Http::timeout(10)->withToken($token)->acceptJson()->post("$base/twirp/livekit.Egress/$method", $body)->throw()->json() ?? [];
    }
}
