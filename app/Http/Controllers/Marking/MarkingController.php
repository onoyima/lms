<?php

namespace App\Http\Controllers\Marking;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\CriterionMark;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\Marker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Examiner marking (Target 60, LMS 66-67, 146-150):
 *  - essays / short answers in a queue, candidates shown by an anonymous code;
 *  - practical checklists and viva scores per candidate (the old assess.php), one row
 *    per examiner, combined by the exam's marking policy.
 */
class MarkingController extends Controller
{
    public function __construct(private Marker $marker, private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $exams = Exam::query()
            ->when(! $user->can('exams.manage'), fn ($q) => $q->whereHas('examiners', fn ($e) => $e->where('users.id', $user->id)))
            ->whereIn('status', ['scheduled', 'live', 'closed', 'results_published'])
            ->latest('id')->limit(100)->get(['id', 'title', 'type'])
            ->map(fn ($e) => [
                'id' => $e->id, 'title' => $e->title, 'type' => $e->type,
                'to_mark' => $e->isPanelMarked() ? null : Attempt::where('exam_id', $e->id)->where('needs_marking', true)->count(),
            ]);

        return Inertia::render('marking/index', ['exams' => $exams]);
    }

    /** Essay marking: next answers needing a mark, anonymised. */
    public function essays(Request $request, Exam $exam): Response
    {
        $this->authorizeExaminer($request, $exam);
        $rows = DB::table('attempt_answers as aa')
            ->join('attempts as a', 'a.id', '=', 'aa.attempt_id')
            ->join('attempt_questions as aq', fn ($j) => $j->on('aq.attempt_id', '=', 'aa.attempt_id')->on('aq.question_id', '=', 'aa.question_id'))
            ->join('questions as q', 'q.id', '=', 'aa.question_id')
            ->where('a.exam_id', $exam->id)->whereIn('a.status', Attempt::CLOSED)
            ->whereIn('q.type', ['essay', 'short_answer'])
            ->when(! $request->boolean('all'), fn ($q) => $q->whereNull('aa.marked_at'))
            ->whereNotNull('aa.response')
            ->orderBy('aa.question_id')->orderBy('aa.attempt_id')->limit(50)
            ->get(['aa.attempt_id', 'aa.question_id', 'aa.response', 'aa.score', 'aa.feedback', 'aq.mark', 'q.stem_html', 'q.explanation_html']);

        return Inertia::render('marking/essays', [
            'exam' => $exam->only(['id', 'title']),
            'answers' => $rows->map(fn ($r) => [
                'attempt_id' => $r->attempt_id, 'question_id' => $r->question_id,
                'candidate_code' => strtoupper(substr(hash('sha256', $r->attempt_id.config('app.key')), 0, 6)), // anonymous marking
                'stem_html' => $r->stem_html, 'guide_html' => $r->explanation_html, 'mark' => (float) $r->mark,
                'text' => json_decode($r->response, true)['text'] ?? '', 'score' => $r->score !== null ? (float) $r->score : null, 'feedback' => $r->feedback,
            ]),
            'remaining' => Attempt::where('exam_id', $exam->id)->where('needs_marking', true)->count(),
        ]);
    }

    public function markEssay(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExaminer($request, $exam);
        $data = $request->validate(['attempt_id' => ['required', 'integer'], 'question_id' => ['required', 'integer'], 'score' => ['required', 'numeric', 'min:0'], 'feedback' => ['nullable', 'string', 'max:5000']]);
        $attempt = Attempt::where('exam_id', $exam->id)->findOrFail($data['attempt_id']);
        abort_if(DB::table('results')->where('exam_id', $exam->id)->where('user_id', $attempt->user_id)->where('locked', true)->exists(), 422, 'This result is approved and locked. Request a change instead.');
        $this->marker->markAnswer($attempt, (int) $data['question_id'], (float) $data['score'], $request->user()->id, $data['feedback'] ?? null);
        $this->audit->record('answer.marked', $attempt, ['question' => $data['question_id'], 'score' => $data['score']]);

        return back();
    }

    /** Practical / viva marking sheet (legacy assess.php). */
    public function panel(Request $request, Exam $exam): Response
    {
        $this->authorizeExaminer($request, $exam);
        abort_unless($exam->isPanelMarked(), 404);
        $examinerId = $request->user()->id;
        $candidates = ExamCandidate::query()->whereIn('exam_session_id', $exam->sessions()->pluck('id'))
            ->with('user:id,name,identifier')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%$s%")->orWhere('identifier', 'like', "%$s%")))
            ->limit(500)->get()->unique('user_id');
        $mine = CriterionMark::where('exam_id', $exam->id)->where('examiner_id', $examinerId)->get()->groupBy('user_id');
        $all = CriterionMark::where('exam_id', $exam->id)->selectRaw('user_id, COUNT(DISTINCT examiner_id) examiners')->groupBy('user_id')->pluck('examiners', 'user_id');

        return Inertia::render('marking/panel', [
            'exam' => $exam->only(['id', 'title', 'type', 'total_mark', 'marking_policy']),
            'criteria' => $exam->criteria()->get(['id', 'text', 'allowed_scores', 'max_score']),
            'candidates' => $candidates->map(fn ($c) => [
                'user_id' => $c->user_id, 'name' => $c->user?->name, 'identifier' => $c->user?->identifier,
                'my_scores' => ($mine[$c->user_id] ?? collect())->mapWithKeys(fn ($m) => [$m->marking_criterion_id => ['score' => $m->score, 'comment' => $m->comment]]),
                'examiners' => (int) ($all[$c->user_id] ?? 0),
                'combined' => $this->marker->panelScore($exam, $c->user_id),
            ])->values(),
        ]);
    }

    public function savePanel(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExaminer($request, $exam);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'scores' => ['required', 'array']]);
        abort_unless(ExamCandidate::whereIn('exam_session_id', $exam->sessions()->pluck('id'))->where('user_id', $data['user_id'])->exists(), 404);
        abort_if(DB::table('results')->where('exam_id', $exam->id)->where('user_id', $data['user_id'])->where('locked', true)->exists(), 422, 'This result is approved and locked. Request a change instead.');
        $this->marker->recordCriterionMarks($exam, (int) $data['user_id'], $request->user()->id, $data['scores']);
        $this->audit->record('panel.marked', $exam, ['candidate' => $data['user_id']]);

        return back()->with('success', 'Scores saved for '.User::find($data['user_id'])?->name.'.');
    }

    private function authorizeExaminer(Request $request, Exam $exam): void
    {
        $user = $request->user();
        abort_unless($user->can('exams.manage') || $exam->examiners()->where('users.id', $user->id)->exists(), 403, 'You are not an examiner for this exam.');
    }
}
