<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\ExamCandidate;
use App\Models\ExamLink;
use App\Models\ExamLinkEntry;
use App\Models\ExamSession;
use App\Models\Question;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\ExamPresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A sitting's control page ("Ready"): the link and code to share, who joined, who is writing and
 * who has submitted, live view and closing early; plus the question-bank count used by exam setup.
 */
class SittingLinkController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** How many questions the bank has for a course (and its topics), to show while filling in the form. */
    public function pool(Request $request, ExamPresets $quick): JsonResponse
    {
        $course = $request->integer('course_id') ?: null;
        $category = in_array($request->string('category')->toString(), ['exam', 'test', 'quiz', 'practice', 'mock'], true) ? $request->string('category')->toString() : 'test';
        $topics = Question::query()->whereIn('status', $category === 'practice' ? ['approved', 'draft', 'in_review'] : ['approved'])
            ->when($course, fn ($q) => $q->where('course_id', $course))
            ->whereNotNull('topic')->where('topic', '!=', '')
            ->selectRaw('topic, count(*) as n')->groupBy('topic')->orderBy('topic')->limit(100)->get()
            ->map(fn ($r) => ['topic' => $r->topic, 'count' => (int) $r->n]);

        return response()->json([
            'available' => $quick->poolSize($course, $request->string('topic')->toString() ?: null, $category),
            'topics' => $topics,
        ]);
    }

    /** Control panel of a sitting: link, code, QR, who has joined and how far they are, live view. */
    public function ready(ExamSession $session): Response
    {
        $session->load('exam');
        $link = ExamLink::where('exam_session_id', $session->id)->first();
        $candidates = ExamCandidate::with('user:id,name,identifier,user_type')->where('exam_session_id', $session->id)->latest('id')->limit(500)->get();
        $attempts = Attempt::whereIn('exam_candidate_id', $candidates->pluck('id'))->get()->groupBy('exam_candidate_id');
        $entries = $link ? ExamLinkEntry::where('exam_link_id', $link->id)->get()->keyBy('exam_candidate_id') : collect();

        $people = $candidates->map(function ($c) use ($attempts, $entries) {
            $a = $attempts->get($c->id)?->sortByDesc('id')->first();
            $e = $entries->get($c->id);

            return [
                'candidate_id' => $c->id, 'name' => $e?->name ?: $c->user?->name, 'identifier' => $e?->identifier ?: $c->user?->identifier,
                'email' => $e?->email, 'guest' => (bool) $e?->guest, 'entry_id' => $e?->id,
                'status' => $a?->status ?? 'not started', 'answered' => (int) ($a?->answered_count ?? 0),
                'risk' => (int) ($a?->risk_score ?? 0), 'score' => $a && $a->score_max ? [(float) $a->score_raw, (float) $a->score_max] : null,
            ];
        })->values();

        $counts = [
            'listed' => $people->count(),
            'writing' => $people->whereIn('status', Attempt::OPEN)->count(),
            'submitted' => $people->whereIn('status', ['submitted', 'auto_submitted', 'terminated'])->count(),
        ];

        return Inertia::render('exams/ready', [
            'exam' => ['id' => $session->exam->id, 'title' => $session->exam->title, 'status' => $session->exam->status, 'category' => $session->exam->category,
                'duration' => $session->exam->duration_minutes, 'protection' => $this->protectionLabel((array) $session->exam->proctoring)],
            'session' => ['id' => $session->id, 'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(),
                'status' => $session->status, 'open' => $session->status === 'open' && $session->ends_at->isFuture()],
            'link' => $link ? ['url' => $link->url(), 'qr' => $this->qr($link->url()), 'code' => $link->code_hint, 'audience' => $link->audience, 'enabled' => $link->enabled] : null,
            'people' => $people,
            'counts' => $counts,
        ]);
    }

    /** Switch the link on/off, change who may use it, set or clear the code, or make a new link. */
    public function saveLink(Request $request, ExamSession $session): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'audience' => ['sometimes', Rule::in(ExamLink::AUDIENCES)],
            'code' => ['nullable', 'string', 'min:4', 'max:12', 'regex:/^[A-Za-z0-9-]+$/'],
            'clear_code' => ['sometimes', 'boolean'],
            'new_link' => ['sometimes', 'boolean'],
        ]);
        $link = ExamLink::firstOrNew(['exam_session_id' => $session->id]);
        if (! $link->exists) {
            $link->fill(['token' => ExamLink::newToken(), 'audience' => 'institution', 'enabled' => true, 'created_by' => $request->user()->id]);
        }
        foreach (['enabled', 'audience'] as $k) {
            if (array_key_exists($k, $data)) {
                $link->{$k} = $data[$k];
            }
        }
        if (! empty($data['code'])) {
            $link->fill(['code_hash' => Hash::make($data['code']), 'code_hint' => $data['code']]);
        } elseif (! empty($data['clear_code'])) {
            $link->fill(['code_hash' => null, 'code_hint' => null]);
        }
        if (! empty($data['new_link'])) {
            $link->token = ExamLink::newToken();
        }
        if ($link->audience === 'anyone' && ! $link->ask) {
            $link->ask = ['identifier'];
        }
        $link->save();
        $this->audit->record('exam_link.saved', $session, array_diff_key($data, ['code' => 1]) + ['code_set' => ! empty($data['code'])]);

        return back()->with('success', 'Exam link updated.');
    }

    /** Let a guest come in again from another computer (their old browser no longer counts). */
    public function releaseEntry(ExamSession $session, ExamLinkEntry $entry): RedirectResponse
    {
        abort_unless($entry->link?->exam_session_id === $session->id, 404);
        $entry->forceFill(['browser_hash' => null])->save();
        $this->audit->record('exam_link.entry_released', $session, ['entry' => $entry->id]);

        return back()->with('success', ($entry->name ?: 'This person').' can now come in again from another computer, using the same email or number.');
    }

    /** Close the sitting now: nobody new can start; anyone already writing keeps the rest of their time. */
    public function close(ExamSession $session): RedirectResponse
    {
        $session->update(['ends_at' => now()->max($session->starts_at)]); // not "closed": people writing can still reload and carry on
        $this->audit->record('session.closed_early', $session, []);

        return back()->with('success', 'The sitting is closed to new starts. Anyone already writing keeps the rest of their time.');
    }

    private function protectionLabel(array $p): string
    {
        return match (true) {
            ! empty($p['record_video']) || ! empty($p['record_screen']) => 'Strict: camera and screen recorded',
            ! empty($p['webcam']) => 'Standard: camera pictures and tab checks',
            default => 'Light: tab and copy checks',
        };
    }

    private function qr(string $url): string
    {
        $svg = (new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(200, 1), new \BaconQrCode\Renderer\Image\SvgImageBackEnd
        )))->writeString($url);

        return trim(substr($svg, strpos($svg, '<svg')));
    }
}
