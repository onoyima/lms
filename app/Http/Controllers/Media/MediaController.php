<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\ExamSession;
use App\Models\MediaRecording;
use App\Models\ProctorAssignment;
use App\Models\Tenant;
use App\Services\Exams\AttemptService;
use App\Services\Media\LiveKit;
use App\Support\Exams\ExamSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Live video, audio and screens through the self-hosted media server: tokens for candidates,
 * their phone (second camera) and proctors; and the server's webhooks, which start recordings
 * and report lost cameras.
 */
class MediaController extends Controller
{
    public function __construct(private LiveKit $live) {}

    /** What this attempt's exam asks the candidate to send. */
    private function sources(Attempt $a): array
    {
        $p = ExamSettings::forCandidate($a->exam->proctoring, $a->user_id, $a->exam_id);

        return array_keys(array_filter([
            'camera' => $p['live_video'] || $p['record_video'],
            'microphone' => $p['microphone'] && ($p['live_video'] || $p['record_video']),
            'screen_share' => $p['screen_share'] && ($p['live_video'] || $p['record_screen']),
        ]));
    }

    /** Live video, recordings and the phone camera belong to the Live proctoring module of the institution's subscription. */
    private function subscribed(Attempt $a): bool
    {
        return app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(Tenant::find($a->tenant_id), 'live_proctoring');
    }

    /** Candidate (attempt token): a token to publish camera, microphone and screen as the exam requires. */
    public function candidate(Request $request): JsonResponse
    {
        $a = $request->attributes->get('attempt');
        abort_unless($a->isOpen(), 409);
        $sources = $this->sources($a);
        if (! $sources || ! $this->live->enabled() || ! $this->subscribed($a)) {
            return response()->json(['enabled' => false]);
        }

        return response()->json(['enabled' => true, 'url' => $this->live->url(), 'token' => $this->live->candidateToken($a, $sources), 'sources' => $sources]);
    }

    /** A one-time link (as a QR code) that turns the candidate's phone into a second camera (Target 111-124). */
    public function phoneLink(Request $request): JsonResponse
    {
        $a = $request->attributes->get('attempt');
        abort_unless($a->isOpen() && ExamSettings::proctoring($a->exam->proctoring)['second_camera'] && $this->subscribed($a), 409, 'This exam does not use a second camera.');
        $code = Str::random(40);
        Cache::put('phone:'.hash('sha256', $code), ['attempt' => $a->id, 'tenant' => $a->tenant_id], now()->addMinutes(10));

        $url = route('phone.show', $code);
        $svg = (new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(200, 1), new \BaconQrCode\Renderer\Image\SvgImageBackEnd
        )))->writeString($url);

        return response()->json(['url' => $url, 'qr' => trim(substr($svg, strpos($svg, "\n") + 1)), 'expires_in' => 600]);
    }

    public function phonePage(string $code): Response
    {
        return Inertia::render('candidate/phone', ['code' => $code, 'valid' => Cache::has('phone:'.hash('sha256', $code))]);
    }

    /** The phone exchanges the link for a camera-only token; the link works once. */
    public function phoneToken(string $code, AttemptService $attempts): JsonResponse
    {
        $entry = Cache::pull('phone:'.hash('sha256', $code));
        abort_unless($entry, 410, 'This link was used or has expired. Make a new one on the exam screen.');
        $a = Attempt::withoutGlobalScopes()->where('tenant_id', $entry['tenant'])->findOrFail($entry['attempt']);
        abort_unless($a->isOpen(), 409, 'The exam is not open.');
        if (! $this->live->enabled()) {
            return response()->json(['message' => 'Live video is not set up on this platform.'], 503);
        }
        app(TenantContext::class)->run(Tenant::find($a->tenant_id), fn () => $attempts->event($a, 'second_camera_linked', [], 1));

        return response()->json(['url' => $this->live->url(), 'token' => $this->live->phoneToken($a), 'name' => $a->user?->name]);
    }

    /** Proctors and the exams office watch the sitting's room (hidden). */
    public function proctor(Request $request, ExamSession $session): JsonResponse
    {
        abort_unless(ProctorAssignment::where('exam_session_id', $session->id)->where('user_id', $request->user()->id)->exists() || $request->user()->can('exams.manage')
            || \App\Support\Exams\ExamAccess::can($request->user(), $session->exam), 403);
        if (! $this->live->enabled()) {
            return response()->json(['enabled' => false]);
        }

        return response()->json(['enabled' => true, 'url' => $this->live->url(), 'token' => $this->live->proctorToken($session, $request->user()->id, $request->user()->name)]);
    }

    /** Media-server webhooks: start recordings when tracks appear, note lost cameras, finish recordings. */
    public function webhook(Request $request, AttemptService $attempts): JsonResponse
    {
        $body = $request->getContent();
        abort_unless($this->live->verifyWebhook($body, $request->header('Authorization')), 401);
        $e = json_decode($body, true) ?: [];
        $room = $e['room']['name'] ?? $e['egressInfo']['roomName'] ?? '';
        if (! preg_match('/^vt(\d+)-s(\d+)$/', $room, $m)) {
            return response()->json(['ok' => true]);
        }

        return app(TenantContext::class)->run(Tenant::find((int) $m[1]), function () use ($e, $attempts) {
            $identity = $e['participant']['identity'] ?? '';
            preg_match('/^c-(\d+)(-phone)?$/', $identity, $who);
            $attempt = $who ? Attempt::find((int) $who[1]) : null;
            $phone = ! empty($who[2]);

            switch ($e['event'] ?? '') {
                case 'track_published':
                    if (! $attempt?->isOpen() || ! $this->subscribed($attempt)) {
                        break;
                    }
                    $p = ExamSettings::proctoring($attempt->exam->proctoring);
                    $source = strtoupper((string) ($e['track']['source'] ?? ''));
                    $kind = $phone ? 'phone' : ($source === 'SCREEN_SHARE' ? 'screen' : ($source === 'CAMERA' ? 'camera' : null));
                    $wanted = $kind && (($kind === 'screen' && $p['record_screen']) || ($kind !== 'screen' && $p['record_video']));
                    if ($wanted && ! MediaRecording::where('attempt_id', $attempt->id)->where('kind', $kind)->whereIn('status', ['starting', 'active'])->exists()) {
                        try {
                            $id = $this->live->startRecording($attempt, $kind);
                            $id && MediaRecording::create(['attempt_id' => $attempt->id, 'kind' => $kind, 'egress_id' => $id, 'status' => 'starting', 'started_at' => now()]);
                        } catch (\Throwable $ex) {
                            $attempts->event($attempt, 'recording_failed', ['kind' => $kind, 'error' => mb_substr($ex->getMessage(), 0, 190)], 2);
                        }
                    }
                    break;
                case 'participant_left':
                    if ($attempt) {
                        // the recording of this connection is finishing; when the candidate comes back, a new part starts
                        MediaRecording::where('attempt_id', $attempt->id)->whereIn('status', ['starting', 'active'])
                            ->whereIn('kind', $phone ? ['phone'] : ['camera', 'screen'])->update(['status' => 'ending']);
                    }
                    if ($attempt?->isOpen()) {
                        $attempts->event($attempt, $phone ? 'second_camera_lost' : 'media_disconnected', [], $phone ? 3 : 2);
                        $phone && $attempts->flagForReview($attempt, 'second_camera_lost', 3);
                    }
                    break;
                case 'egress_started':
                case 'egress_updated':
                case 'egress_ended':
                    $info = $e['egressInfo'] ?? [];
                    $rec = MediaRecording::where('egress_id', $info['egressId'] ?? '')->first();
                    if ($rec) {
                        $status = match ($info['status'] ?? '') {
                            'EGRESS_ACTIVE' => 'active', 'EGRESS_COMPLETE' => 'complete', 'EGRESS_FAILED', 'EGRESS_ABORTED', 'EGRESS_LIMIT_REACHED' => 'failed', default => $rec->status,
                        };
                        $file = $info['fileResults'][0] ?? $info['file'] ?? [];
                        $rec->update(['status' => $status, 'location' => $file['location'] ?? $file['filename'] ?? $rec->location,
                            'size' => $file['size'] ?? $rec->size, 'duration_seconds' => isset($file['duration']) ? (int) round($file['duration'] / 1e9) : $rec->duration_seconds,
                            'error' => isset($info['error']) ? mb_substr($info['error'], 0, 250) : null, 'ended_at' => $status === 'complete' || $status === 'failed' ? now() : null]);
                        // a recording that stopped on its own while the candidate is still writing: carry on in a new part
                        $owner = Attempt::find($rec->attempt_id);
                        if (($e['event'] ?? '') === 'egress_ended' && $owner?->isOpen() && in_array($info['status'] ?? '', ['EGRESS_LIMIT_REACHED', 'EGRESS_FAILED', 'EGRESS_ABORTED'], true)
                            && ! MediaRecording::where('attempt_id', $owner->id)->where('kind', $rec->kind)->whereIn('status', ['starting', 'active'])->exists()) {
                            try {
                                $id = $this->live->startRecording($owner, $rec->kind);
                                $id && MediaRecording::create(['attempt_id' => $owner->id, 'kind' => $rec->kind, 'egress_id' => $id, 'status' => 'starting', 'started_at' => now()]);
                            } catch (\Throwable $ex) {
                                $attempts->event($owner, 'recording_failed', ['kind' => $rec->kind, 'error' => mb_substr($ex->getMessage(), 0, 190)], 2);
                            }
                        }
                    }
                    break;
            }

            return response()->json(['ok' => true]);
        });
    }
}
