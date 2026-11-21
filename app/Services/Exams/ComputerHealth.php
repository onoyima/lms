<?php

namespace App\Services\Exams;

use App\Models\Attempt;
use App\Models\ComputerCheck;
use App\Models\ComputerReport;
use App\Models\ExamComputer;
use App\Models\ExamSession;
use App\Models\ProctoringEvent;
use App\Support\Exams\ExamSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Computer checks before an exam, and computer problems during one. Every report carries the IP
 * address the server saw, so ICT can walk straight to the machine.
 */
class ComputerHealth
{
    /** Events recorded during exams that point to a computer or network problem (not misconduct). */
    public const TECHNICAL_EVENTS = [
        'network_offline' => 'Lost the network', 'device_off' => 'Computer went off', 'device_asleep' => 'Computer went to sleep',
        'camera_off' => 'Camera stopped', 'microphone_off' => 'Microphone not available', 'media_disconnected' => 'Live video dropped',
        'media_poor' => 'Poor video link', 'recording_failed' => 'Recording failed', 'screen_share_stopped' => 'Screen sharing stopped',
        'second_camera_lost' => 'Phone camera lost',
    ];

    public function create(array $data, int $by): ComputerCheck
    {
        $session = ! empty($data['exam_session_id']) ? ExamSession::with('exam')->find($data['exam_session_id']) : null;
        $requirements = $data['requirements'] ?? ($session ? $this->requirementsFor($session) : []);

        return ComputerCheck::create([
            'name' => $data['name'] ?: ($session ? 'Before '.$session->exam?->title : 'Computer check'),
            'code' => Str::lower(Str::random(8)),
            'exam_session_id' => $session?->id,
            'requirements' => array_values(array_intersect((array) $requirements, array_keys(ComputerCheck::TESTS))),
            'opens_at' => now(),
            'closes_at' => now()->addHours(max(1, min(168, (int) ($data['hours'] ?? 12)))),
            'created_by' => $by,
        ]);
    }

    /** What a sitting's exam will ask of each computer. */
    public function requirementsFor(ExamSession $session): array
    {
        $p = ExamSettings::proctoring($session->exam?->proctoring);
        $channels = (array) ($session->exam?->channels ?? ['web']);

        return array_keys(array_filter([
            'fullscreen' => $p['require_fullscreen'],
            'camera' => $p['webcam'] || $p['face_detection'] || $p['live_video'] || $p['record_video'] || ($p['identity_check'] ?? 'none') !== 'none',
            'microphone' => $p['microphone'],
            'secure_app' => ! in_array('web', $channels, true),
        ]));
    }

    /** One computer's results from the check page. */
    public function record(ComputerCheck $check, Request $request, array $results, ?string $label): ComputerReport
    {
        $tests = $check->tests();
        $clean = [];
        foreach ($results as $r) {
            $key = (string) ($r['key'] ?? '');
            if (in_array($key, $tests, true)) {
                $clean[$key] = ['key' => $key, 'ok' => (bool) ($r['ok'] ?? false), 'warn' => (bool) ($r['warn'] ?? false), 'detail' => mb_substr((string) ($r['detail'] ?? ''), 0, 200)];
            }
        }
        foreach (array_diff($tests, array_keys($clean)) as $missing) {
            $clean[$missing] = ['key' => $missing, 'ok' => false, 'warn' => false, 'detail' => 'Not tested'];
        }
        $failed = array_filter($clean, fn ($r) => ! $r['ok']);
        $status = $failed ? 'fail' : (array_filter($clean, fn ($r) => $r['warn']) ? 'warn' : 'pass');
        $label = $label !== null ? mb_substr(trim($label), 0, 60) ?: null : null;
        $now = now();

        return DB::transaction(function () use ($check, $request, $clean, $status, $failed, $label, $now) {
            $row = ComputerReport::where('computer_check_id', $check->id)->where('ip', $request->ip())->where('label', $label)->lockForUpdate()->first()
                ?? new ComputerReport(['computer_check_id' => $check->id, 'kind' => 'check', 'ip' => $request->ip(), 'label' => $label, 'first_seen_at' => $now, 'tenant_id' => $check->tenant_id]);
            $row->forceFill([
                'tenant_id' => $check->tenant_id, 'exam_session_id' => $check->exam_session_id,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'status' => $status,
                'results' => array_values($clean), 'error_count' => count($failed), 'last_seen_at' => $now,
                'errors' => array_values(array_map(fn ($r) => ComputerCheck::TESTS[$r['key']].': '.$r['detail'], $failed)),
            ])->save();

            return $row;
        });
    }

    /** A problem the exam screen noticed on this computer (a script error, a failed save, full storage…). */
    public function problem(Attempt $attempt, Request $request, string $kind, string $message): void
    {
        $now = now();
        DB::transaction(function () use ($attempt, $request, $kind, $message, $now) {
            $row = ComputerReport::where('attempt_id', $attempt->id)->where('ip', $request->ip())->lockForUpdate()->first()
                ?? new ComputerReport(['attempt_id' => $attempt->id, 'kind' => 'exam', 'ip' => $request->ip(), 'first_seen_at' => $now]);
            $errors = array_slice(array_merge((array) $row->errors, [['at' => $now->toIso8601String(), 'kind' => $kind, 'message' => mb_substr($message, 0, 300)]]), -20);
            $row->forceFill([
                'tenant_id' => $attempt->tenant_id, 'exam_session_id' => $attempt->exam_session_id, 'status' => 'fail',
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'errors' => $errors,
                'error_count' => $row->error_count + 1, 'last_seen_at' => $now,
            ])->save();
        });
    }

    /** Rows for a check's live page. */
    public function checkRows(ComputerCheck $check): array
    {
        $approved = ExamComputer::current()->pluck('status', 'ip');

        return $check->reports()->orderByRaw("CASE status WHEN 'fail' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")->orderBy('ip')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'ip' => $r->ip, 'label' => $r->label, 'status' => $r->status, 'results' => $r->results ?? [],
                'browser' => self::browser((string) $r->user_agent), 'last_seen' => $r->last_seen_at?->toIso8601String(),
                'approval' => $approved[$r->ip] ?? null,
            ])->all();
    }

    /**
     * Computer problems during exams: what the exam screens reported plus technical events (network
     * lost, computer off, camera stopped), one row per candidate and computer, newest first.
     */
    public function examProblems(int $hours = 24, ?int $sessionId = null): array
    {
        $since = now()->subHours($hours);
        $events = ProctoringEvent::query()->whereIn('type', array_keys(self::TECHNICAL_EVENTS))->where('created_at', '>=', $since)
            ->when($sessionId, fn ($q) => $q->whereIn('attempt_id', Attempt::where('exam_session_id', $sessionId)->select('id')))
            ->selectRaw('attempt_id, type, COUNT(*) n, MAX(created_at) last')->groupBy('attempt_id', 'type')->get()->groupBy('attempt_id');
        $reports = ComputerReport::where('kind', 'exam')->where('last_seen_at', '>=', $since)
            ->when($sessionId, fn ($q) => $q->where('exam_session_id', $sessionId))->get()->groupBy('attempt_id');
        $ids = $events->keys()->merge($reports->keys())->unique()->values();
        $attempts = Attempt::whereIn('id', $ids)->with(['user:id,name,identifier', 'session:id,exam_id,name', 'session.exam:id,title'])->get()->keyBy('id');

        return $ids->map(function ($id) use ($attempts, $events, $reports) {
            $a = $attempts[$id] ?? null;
            if (! $a) {
                return null;
            }
            $problems = collect($events[$id] ?? [])->map(fn ($e) => ['label' => self::TECHNICAL_EVENTS[$e->type] ?? $e->type, 'count' => (int) $e->n, 'last' => (string) $e->last]);
            $reported = collect($reports[$id] ?? []);
            foreach ($reported as $r) {
                foreach (collect($r->errors)->groupBy('kind') as $kind => $list) {
                    $problems->push(['label' => self::kindLabel((string) $kind), 'count' => $list->count(), 'last' => (string) $list->last()['at'], 'message' => (string) $list->last()['message']]);
                }
            }

            return [
                'attempt_id' => $a->id, 'name' => $a->user?->name, 'identifier' => $a->user?->identifier,
                'exam' => $a->session?->exam?->title, 'session_id' => $a->exam_session_id, 'status' => $a->status,
                'ip' => $reported->last()?->ip ?? $a->ip, 'browser' => self::browser((string) ($reported->last()?->user_agent ?? $a->user_agent)),
                'problems' => $problems->sortByDesc('last')->values()->all(),
                'last' => $problems->max('last'),
            ];
        })->filter()->sortByDesc('last')->values()->all();
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'script_error' => 'Exam screen error', 'save_failed' => 'Answers not saving', 'storage_full' => 'Computer storage full',
            'server_error' => 'Server error', 'camera_error' => 'Camera error', default => ucfirst(str_replace('_', ' ', $kind)),
        };
    }

    /** "Chrome 131 on Windows" from a user agent. */
    public static function browser(string $ua): string
    {
        $b = match (true) {
            str_contains($ua, 'SEB') => 'Safe Exam Browser',
            (bool) preg_match('#Edg/(\d+)#', $ua, $m) => 'Edge '.$m[1],
            (bool) preg_match('#Firefox/(\d+)#', $ua, $m) => 'Firefox '.$m[1],
            (bool) preg_match('#Chrome/(\d+)#', $ua, $m) => 'Chrome '.$m[1],
            (bool) preg_match('#Version/(\d+).*Safari#', $ua, $m) => 'Safari '.$m[1],
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'CrOS') => 'ChromeOS', str_contains($ua, 'Linux') => 'Linux', default => '',
        };

        return trim($b.($os ? ' on '.$os : ''));
    }

    /** Approve every computer that passed this check (for exams limited to approved computers). */
    public function approvePassed(ComputerCheck $check, int $by): int
    {
        $n = 0;
        foreach ($check->reports()->where('status', '!=', 'fail')->pluck('ip')->unique() as $ip) {
            $row = ExamComputer::current()->where('ip', $ip)->first()
                ?? ExamComputer::create(['ip' => $ip, 'status' => 'pending', 'requests' => 0, 'first_seen_at' => now(), 'last_seen_at' => now()]);
            app(ExamComputers::class)->decide($row, 'approved', $by);
            $n++;
        }

        return $n;
    }
}
