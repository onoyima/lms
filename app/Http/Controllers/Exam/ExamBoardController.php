<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\Question;
use App\Models\QuestionImport;
use App\Models\Result;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\CandidateRoster;
use App\Services\Exams\Marker;
use App\Services\Questions\Import\QuestionImportService;
use App\Support\Exams\ExamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The exam board: every exam with who is writing it now, and for each exam its statistics,
 * score board, grade distribution, rescaled scores and download. The exams office sees every
 * exam; examiners and course lecturers see the exams assigned to them (ExamAccess).
 */
class ExamBoardController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tab = in_array($request->string('show')->toString(), ['active', 'upcoming', 'past', 'all'], true) ? $request->string('show')->toString() : 'active';
        $now = now();

        $windows = ExamSession::query()->selectRaw('exam_id, MIN(starts_at) first_start, MAX(ends_at) last_end, SUM(CASE WHEN starts_at <= ? AND ends_at >= ? THEN 1 ELSE 0 END) running', [$now, $now])->groupBy('exam_id');
        $base = ExamAccess::scope(Exam::query(), $user)
            ->leftJoinSub($windows, 'w', 'w.exam_id', '=', 'exams.id')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(fn ($w) => $w->where('exams.title', 'like', '%'.addcslashes($s, '%_').'%')->orWhere('exams.code', 'like', '%'.addcslashes($s, '%_').'%')));

        $writing = Attempt::query()->whereIn('status', Attempt::OPEN)->select('exam_id');
        $counts = [
            'active' => (clone $base)->where(fn ($q) => $q->where('w.running', '>', 0)->orWhereIn('exams.id', $writing))->count(),
            'upcoming' => (clone $base)->where('w.first_start', '>', $now)->count(),
            'past' => (clone $base)->where('w.last_end', '<', $now)->count(),
            'all' => (clone $base)->count(),
        ];

        $exams = (clone $base)->select('exams.*', 'w.first_start', 'w.last_end', 'w.running')->with('course:id,code,title')
            ->when($tab === 'active', fn ($q) => $q->where(fn ($w) => $w->where('w.running', '>', 0)->orWhereIn('exams.id', $writing)))
            ->when($tab === 'upcoming', fn ($q) => $q->where('w.first_start', '>', $now)->orderBy('w.first_start'))
            ->when($tab === 'past', fn ($q) => $q->where('w.last_end', '<', $now)->orderByDesc('w.last_end'))
            ->orderByDesc('exams.id')->paginate(30)->withQueryString();

        $ids = $exams->pluck('id');
        $candidates = ExamCandidate::query()->join('exam_sessions', 'exam_sessions.id', '=', 'exam_candidates.exam_session_id')
            ->whereIn('exam_sessions.exam_id', $ids)->selectRaw('exam_sessions.exam_id, COUNT(*) n')->groupBy('exam_sessions.exam_id')->pluck('n', 'exam_id');
        $attempts = Attempt::query()->whereIn('exam_id', $ids)
            ->selectRaw("exam_id, COUNT(DISTINCT CASE WHEN status IN ('in_progress','paused','locked') THEN user_id END) writing, COUNT(DISTINCT CASE WHEN status IN ('submitted','auto_submitted','terminated') THEN user_id END) finished")
            ->groupBy('exam_id')->get()->keyBy('exam_id');
        $results = Result::query()->whereIn('exam_id', $ids)->selectRaw('exam_id, AVG(CASE WHEN max_score > 0 THEN raw_score / max_score * 100 END) avg_pct')->groupBy('exam_id')->pluck('avg_pct', 'exam_id');

        return Inertia::render('exams/board', [
            'tab' => $tab,
            'counts' => $counts,
            'q' => $request->string('q')->toString(),
            'managesAll' => ExamAccess::managesAll($user),
            'exams' => $exams->through(fn (Exam $e) => [
                'id' => $e->id, 'title' => $e->title, 'code' => $e->code, 'type' => $e->type, 'status' => $e->status,
                'course' => $e->course ? $e->course->code : null,
                'first_start' => $e->first_start ? \Illuminate\Support\Carbon::parse($e->first_start)->toIso8601String() : null,
                'last_end' => $e->last_end ? \Illuminate\Support\Carbon::parse($e->last_end)->toIso8601String() : null,
                'running' => (int) $e->running > 0,
                'candidates' => (int) ($candidates[$e->id] ?? 0),
                'writing' => (int) ($attempts[$e->id]->writing ?? 0),
                'finished' => (int) ($attempts[$e->id]->finished ?? 0),
                'average' => isset($results[$e->id]) ? round((float) $results[$e->id], 1) : null,
            ]),
        ]);
    }

    public function show(Request $request, Exam $exam): Response
    {
        ExamAccess::authorize($request->user(), $exam);
        $exam->load('course:id,code,title');
        $scale = $this->scale($request, $exam);
        $board = $this->board($exam);
        $scored = $board->filter(fn ($r) => $r['percent'] !== null);
        $pcts = $scored->pluck('percent')->sort()->values();
        $passPct = $this->passPercent($exam);

        $sessions = $exam->sessions()->with('venue:id,name')->withCount('candidates')->orderBy('starts_at')->get();
        $writingBySession = Attempt::where('exam_id', $exam->id)->whereIn('status', Attempt::OPEN)->selectRaw('exam_session_id, COUNT(*) n')->groupBy('exam_session_id')->pluck('n', 'exam_session_id');

        return Inertia::render('exams/board-exam', [
            'exam' => [
                ...$exam->only(['id', 'title', 'code', 'type', 'status', 'duration_minutes', 'max_attempts']),
                'course' => $exam->course?->only(['code', 'title']),
                'total_mark' => $exam->total_mark !== null ? (float) $exam->total_mark : null,
                'pass_mark' => $exam->pass_mark !== null ? (float) $exam->pass_mark : null,
                'pass_percent' => $passPct,
                'editable' => in_array($exam->status, ['draft', 'returned'], true),
                'on_screen' => $exam->isOnScreen(),
                'questions' => DB::table('exam_section_questions')->join('exam_sections', 'exam_sections.id', '=', 'exam_section_questions.exam_section_id')->where('exam_sections.exam_id', $exam->id)->count(),
            ],
            'scale' => $scale,
            'summary' => [
                'candidates' => $board->count(),
                'started' => $board->where('attempts', '>', 0)->count(),
                'writing' => $board->whereIn('status', Attempt::OPEN)->count(),
                'submitted' => $board->whereIn('status', Attempt::CLOSED)->count(),
                'not_started' => $board->where('attempts', 0)->count(),
                'marking' => $board->where('marking', true)->count(),
                'mean' => $pcts->count() ? round($pcts->avg(), 1) : null,
                'median' => $pcts->count() ? round((float) $pcts->median(), 1) : null,
                'highest' => $pcts->count() ? round((float) $pcts->max(), 1) : null,
                'lowest' => $pcts->count() ? round((float) $pcts->min(), 1) : null,
                'std_dev' => $pcts->count() > 1 ? round(sqrt($pcts->map(fn ($p) => ($p - $pcts->avg()) ** 2)->sum() / ($pcts->count() - 1)), 1) : null,
                'passed' => $scored->where('percent', '>=', $passPct)->count(),
                'failed' => $scored->where('percent', '<', $passPct)->count(),
            ],
            'distribution' => $this->distribution($pcts, $passPct),
            'sessions' => $sessions->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'venue' => $s->venue?->name, 'mode' => $s->mode, 'status' => $s->paused_at ? 'paused' : $s->status,
                'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                'candidates' => $s->candidates_count, 'writing' => (int) ($writingBySession[$s->id] ?? 0),
                'running' => $s->starts_at->lte(now()) && $s->ends_at->gte(now()),
            ]),
            'board' => $board->map(fn ($r) => $r + ['scaled' => self::scaled($r, $scale)])->values(),
            'can' => [
                'manage' => ExamAccess::managesAll($request->user()),
                'rescale' => ! Result::where('exam_id', $exam->id)->where(fn ($q) => $q->where('locked', true)->orWhereIn('status', ['approved', 'published']))->exists(),
                'add_questions' => $exam->isOnScreen() && in_array($exam->status, ['draft', 'returned'], true),
            ],
        ]);
    }

    /** Scores as an Excel workbook (default), CSV or PDF, out of the chosen scale. */
    public function export(Request $request, Exam $exam, \App\Services\Reports\Exporter $exporter)
    {
        ExamAccess::authorize($request->user(), $exam);
        $scale = $this->scale($request, $exam);
        $format = in_array($request->query('format'), ['xlsx', 'csv', 'pdf'], true) ? $request->query('format') : 'xlsx';
        $passPct = $this->passPercent($exam);
        $board = $this->board($exam);
        $this->audit->record('exam.board_exported', $exam, ['scale' => $scale, 'rows' => $board->count(), 'format' => $format]);
        $label = rtrim(rtrim(number_format($scale, 2, '.', ''), '0'), '.');
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $exam->code ?: $exam->title).'-scores-out-of-'.$label;
        $columns = ['Name', 'Matric number', 'Sitting', 'Status', 'Attempts', 'Started', 'Submitted', 'Minutes taken', 'Score', 'Out of', 'Percentage', "Score out of $label", 'Result'];
        $rows = $board->map(fn ($r) => [
            $r['name'], $r['identifier'], $r['sitting'], str_replace('_', ' ', $r['status']), $r['attempts'],
            $r['started_at'] ? \Illuminate\Support\Carbon::parse($r['started_at'])->format('Y-m-d H:i') : '',
            $r['submitted_at'] ? \Illuminate\Support\Carbon::parse($r['submitted_at'])->format('Y-m-d H:i') : '',
            $r['minutes'], $r['raw'], $r['max'], $r['percent'], self::scaled($r, $scale),
            $r['percent'] === null ? ($r['marking'] ? 'awaiting marking' : '') : ($r['percent'] >= $passPct ? 'pass' : 'fail'),
        ])->all();

        return match ($format) {
            'csv' => $exporter->csv($name, $columns, $rows),
            'pdf' => $exporter->pdf($name, 'pdf.report', ['title' => $exam->title.' — scores out of '.$label, 'columns' => $columns, 'rows' => $rows, 'truncated' => false,
                'subtitle' => 'Pass mark '.$passPct.' % · '.count($rows).' candidates · '.now()->format('j M Y H:i'), 'footer' => 'Confidential — '.$request->user()->name], 'landscape'),
            default => $exporter->xlsx($name, 'Scores', $columns, $rows),
        };
    }

    /** Make a scale the exam's official total, so results and transcripts use it (before results are approved). */
    public function applyScale(Request $request, Exam $exam, Marker $marker): RedirectResponse
    {
        ExamAccess::authorize($request->user(), $exam);
        $total = (float) $request->validate(['total_mark' => ['required', 'numeric', 'min:1', 'max:1000']])['total_mark'];
        abort_if(Result::where('exam_id', $exam->id)->where(fn ($q) => $q->where('locked', true)->orWhereIn('status', ['approved', 'published']))->exists(), 422,
            'Results for this exam are approved. Ask the exams office to request a change instead.');
        $old = $exam->total_mark;
        $exam->forceFill(['total_mark' => $total])->save();
        $users = $exam->isPanelMarked()
            ? \App\Models\CriterionMark::where('exam_id', $exam->id)->distinct()->pluck('user_id')
            : $exam->attempts()->whereIn('status', Attempt::CLOSED)->distinct()->pluck('user_id');
        foreach ($users as $uid) {
            $marker->refreshResult($exam, $uid);
        }
        $this->audit->record('exam.rescaled', $exam, ['from' => $old, 'to' => $total, 'results' => $users->count()]);

        return back()->with('success', 'Scores are now out of '.rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.').' for '.$users->count().' students.');
    }

    public function addCandidates(Request $request, Exam $exam, CandidateRoster $roster): RedirectResponse
    {
        ExamAccess::authorize($request->user(), $exam);
        $data = $request->validate([
            'session_id' => ['required', 'integer'],
            'identifiers' => ['nullable', 'string', 'max:200000'],
            'from_enrolment' => ['boolean'],
        ]);
        $session = ExamSession::where('exam_id', $exam->id)->findOrFail($data['session_id']);
        abort_if($session->status === 'closed', 422, 'This sitting has closed.');

        return back()->with('success', $roster::message($roster->add($session, $data)));
    }

    // ── Questions uploaded straight into this exam ───────────────────────────

    public function upload(Request $request, Exam $exam, QuestionImportService $imports): RedirectResponse
    {
        ExamAccess::authorize($request->user(), $exam);
        $this->assertQuestionsEditable($exam);
        $cfg = config('platform.question_import');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$cfg['max_upload_kb'], 'extensions:'.implode(',', $cfg['allowed_extensions'])],
            'reader' => ['nullable', 'in:standard,science'],
        ]);
        $import = $imports->upload($data['file'], $exam->course_id, $request->user()->id, $data['reader'] ?? 'science');

        return redirect()->route('exam-board.import', [$exam, $import]);
    }

    public function preview(Request $request, Exam $exam, QuestionImport $import, QuestionImportService $imports): Response
    {
        ExamAccess::authorize($request->user(), $exam);
        abort_unless($import->user_id === $request->user()->id || ExamAccess::managesAll($request->user()), 403);
        $result = $import->status === 'failed' ? ['questions' => [], 'warnings' => []] : $imports->parse($import);

        return Inertia::render('question-bank/import-preview', [
            'import' => $import->only(['id', 'original_name', 'status', 'question_count', 'warnings', 'format']),
            'questions' => $result['questions'],
            'documentWarnings' => $result['warnings'],
            'commitUrl' => route('exam-board.import.commit', [$exam, $import], false),
            'commitLabel' => 'Add these questions to '.$exam->title,
            'backUrl' => route('exam-board.show', $exam, false),
        ]);
    }

    public function commit(Request $request, Exam $exam, QuestionImport $import, QuestionImportService $imports): RedirectResponse
    {
        ExamAccess::authorize($request->user(), $exam);
        abort_unless($import->user_id === $request->user()->id || ExamAccess::managesAll($request->user()), 403);
        $this->assertQuestionsEditable($exam);
        $count = DB::transaction(function () use ($exam, $import, $imports, $request) {
            $count = $imports->commit($import, $request->user()->id);
            $section = $exam->sections()->first() ?? $exam->sections()->create(['title' => 'Section A', 'position' => 0]);
            $start = $section->questions()->count();
            $ids = Question::where('question_import_id', $import->id)->orderBy('id')->pluck('id');
            $section->questions()->syncWithoutDetaching($ids->values()->mapWithKeys(fn ($id, $i) => [$id => ['position' => $start + $i]])->all());

            return $count;
        });
        $this->audit->record('exam.questions_uploaded', $exam, ['import' => $import->id, 'questions' => $count]);

        return redirect()->route('exam-board.show', $exam)->with('success', "$count questions added to this exam (also saved in the question bank as drafts).");
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * One row per candidate: status of their latest attempt, times, and the score that counts
     * (from the result once marked). Scores are empty while a candidate is still writing.
     */
    private function board(Exam $exam): Collection
    {
        $sessions = $exam->sessions()->get(['id', 'name', 'starts_at', 'ends_at'])->keyBy('id');
        $candidates = ExamCandidate::query()->whereIn('exam_session_id', $sessions->keys())->with('user:id,name,identifier')->limit(10000)->get();
        $attempts = Attempt::where('exam_id', $exam->id)->orderBy('attempt_no')->get(['id', 'exam_candidate_id', 'user_id', 'status', 'started_at', 'submitted_at', 'score_raw', 'score_max', 'needs_marking'])->groupBy('exam_candidate_id');
        $results = Result::where('exam_id', $exam->id)->get(['user_id', 'raw_score', 'max_score', 'final_score', 'status'])->keyBy('user_id');

        return $candidates->map(function (ExamCandidate $c) use ($sessions, $attempts, $results, $exam) {
            $list = $attempts[$c->id] ?? collect();
            $last = $list->last();
            $session = $sessions[$c->exam_session_id] ?? null;
            $result = $results[$c->user_id] ?? null;
            $closed = $last && in_array($last->status, Attempt::CLOSED, true);
            $marking = $closed && (bool) $last->needs_marking;
            [$raw, $max] = match (true) {
                $exam->isPanelMarked() => $result ? [$result->raw_score, $result->max_score] : [null, null],
                ! $closed || $marking => [null, null],
                $result && $result->max_score > 0 => [$result->raw_score, $result->max_score],
                default => [$last->score_raw !== null ? (float) $last->score_raw : null, $last->score_max !== null ? (float) $last->score_max : null],
            };
            $status = $last?->status ?? ($session && $session->ends_at->isPast() ? 'absent' : 'not_started');
            if ($exam->isPanelMarked()) {
                $status = $result ? 'scored' : 'not_scored';
            }

            return [
                'candidate_id' => $c->id, 'name' => $c->user?->name, 'identifier' => $c->user?->identifier,
                'sitting' => $session?->name ?: ($session ? $session->starts_at->format('d M H:i') : null), 'session_id' => $c->exam_session_id,
                'status' => $status, 'attempts' => $list->count(),
                'started_at' => $last?->started_at?->toIso8601String(), 'submitted_at' => $last?->submitted_at?->toIso8601String(),
                'minutes' => $last && $last->submitted_at ? (int) round($last->started_at->diffInSeconds($last->submitted_at) / 60) : null,
                'raw' => $raw !== null ? round((float) $raw, 2) : null, 'max' => $max !== null ? round((float) $max, 2) : null,
                'percent' => $raw !== null && $max > 0 ? round($raw / $max * 100, 1) : null,
                'marking' => $marking,
            ];
        })->sortBy([['percent', 'desc'], ['name', 'asc']])->values();
    }

    /** A score out of the chosen scale, from the marks themselves (not the rounded percentage). */
    private static function scaled(array $r, float $scale): ?float
    {
        return $r['raw'] !== null && $r['max'] > 0 ? round($r['raw'] / $r['max'] * $scale, 2) : null;
    }

    /** Moodle-style bands: 0–10, 11–20 … 91–100 (% of the maximum). */
    private function distribution(Collection $pcts, float $passPct): array
    {
        $bands = [];
        for ($i = 0; $i < 10; $i++) {
            $bands[] = ['label' => ($i === 0 ? 0 : $i * 10 + 1).'–'.(($i + 1) * 10), 'from' => $i * 10, 'to' => ($i + 1) * 10, 'count' => 0, 'pass' => ($i + 1) * 10 > $passPct];
        }
        foreach ($pcts as $p) {
            $i = $p <= 10 ? 0 : min(9, (int) ceil($p / 10) - 1);
            $bands[$i]['count']++;
        }

        return $bands;
    }

    private function scale(Request $request, Exam $exam): float
    {
        $v = $request->query('scale');

        return is_numeric($v) && (float) $v > 0 && (float) $v <= 1000 ? round((float) $v, 2) : ($exam->total_mark ? (float) $exam->total_mark : 100.0);
    }

    /** The pass mark as a percentage (pass mark is set in the exam's own marks). */
    private function passPercent(Exam $exam): float
    {
        if ($exam->pass_mark === null) {
            return 40.0;
        }
        $outOf = $exam->total_mark ? (float) $exam->total_mark : (float) (Result::where('exam_id', $exam->id)->max('max_score') ?: 100);

        return $outOf > 0 ? round(min(100, (float) $exam->pass_mark / $outOf * 100), 1) : 40.0;
    }

    private function assertQuestionsEditable(Exam $exam): void
    {
        abort_unless($exam->isOnScreen(), 422, 'This exam is scored by examiners; it has no questions.');
        abort_unless(in_array($exam->status, ['draft', 'returned'], true), 422, 'This exam is approved or live. The exams office must reopen it before questions can be added.');
    }
}
