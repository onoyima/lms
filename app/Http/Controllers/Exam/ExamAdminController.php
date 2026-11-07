<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\MarkingCriterion;
use App\Models\Question;
use App\Models\Semester;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\ExamWorkflow;
use App\Support\Exams\ExamSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Creating and configuring exams of every format (Target 14-20, 48-49, 75-88, 104, 206). */
class ExamAdminController extends Controller
{
    public function __construct(private AuditLogger $audit, private ExamWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $exams = Exam::query()->with('course:id,code')
            ->withCount(['sessions', 'attempts', 'results'])
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('type', $t))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where('title', 'like', "%$s%"))
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn (Exam $e) => [
                'id' => $e->id, 'title' => $e->title, 'type' => $e->type, 'category' => $e->category, 'status' => $e->status,
                'course' => $e->course?->code, 'duration' => $e->duration_minutes, 'sessions' => $e->sessions_count,
                'attempts' => $e->attempts_count, 'results' => $e->results_count,
            ]);

        return Inertia::render('exams/index', ['exams' => $exams, 'filters' => $request->only(['type', 'status', 'q'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateExam($request);
        $exam = DB::transaction(function () use ($data, $request) {
            $exam = Exam::create($data + [
                'created_by' => $request->user()->id,
                'settings' => ExamSettings::SETTINGS,
                'proctoring' => ExamSettings::defaultsFor($data['type'], $data['course_id'] ?? null),
                'channels' => ['web', 'desktop', 'seb'],
            ]);
            if ($exam->isOnScreen()) {
                $exam->sections()->create(['title' => $exam->type === 'essay' ? 'Section A — Essay' : 'Section A', 'position' => 0]);
            }
            if (in_array($exam->type, ['viva', 'research'], true)) {
                // Viva (legacy) and research: one overall score out of the exam total, picked from 0 up to the maximum
                $exam->criteria()->create(['text' => $exam->type === 'viva' ? 'General viva performance' : 'Research work and defence', 'allowed_scores' => null, 'max_score' => $exam->total_mark ?: 100, 'position' => 0]);
            }

            return $exam;
        });
        $this->audit->record('exam.created', $exam, ['type' => $exam->type]);

        return redirect()->route('exams.show', $exam)->with('success', 'Exam created. Add its questions or criteria next.');
    }

    public function show(Exam $exam): Response
    {
        $exam->load([
            'course:id,code,title', 'semester:id,name',
            'sections.questions:id,type,stem_html,mark,topic,difficulty,status',
            'criteria', 'examiners:id,name,email,user_type',
            'sessions' => fn ($q) => $q->withCount(['candidates', 'attempts'])->with('venue:id,name')->orderBy('starts_at'),
            'approvals' => fn ($q) => $q->latest('id')->limit(20),
        ]);

        return Inertia::render('exams/show', [
            'exam' => [
                ...$exam->only(['id', 'title', 'code', 'type', 'category', 'kind', 'parent_exam_id', 'status', 'instructions', 'total_mark', 'pass_mark', 'duration_minutes', 'max_attempts', 'score_policy', 'marking_policy', 'version', 'course_id', 'semester_id', 'channels']),
                'settings' => ExamSettings::settings($exam->settings) + ['seb' => data_get($exam->settings, 'seb', [])],
                'proctoring' => ExamSettings::proctoring($exam->proctoring),
                'course' => $exam->course, 'semester' => $exam->semester,
                'is_panel' => $exam->isPanelMarked(),
                'can_edit' => in_array($exam->status, ['draft', 'returned'], true),
            ],
            'sections' => $exam->sections->map(fn ($s) => [
                'id' => $s->id, 'title' => $s->title, 'instructions' => $s->instructions, 'blueprint' => $s->blueprint ?? [], 'answer_any' => $s->answer_any,
                'questions' => $s->questions->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'stem_html' => $q->stem_html, 'mark' => (float) ($q->pivot->mark ?? $q->mark), 'topic' => $q->topic, 'status' => $q->status]),
            ]),
            'criteria' => $exam->criteria,
            'examiners' => $exam->examiners->where('user_type', '!=', \App\Models\ExamPanelAccount::USER_TYPE)->values()->map(fn ($u) => $u->only(['id', 'name', 'email', 'pivot'])),
            'examinerAccounts' => $exam->isPanelMarked() ? \App\Models\ExamPanelAccount::where('exam_id', $exam->id)->whereNull('revoked_at')->orderBy('last_name')->get()
                ->map(fn ($a) => ['id' => $a->id, 'first_name' => $a->first_name, 'last_name' => $a->last_name, 'username' => $a->username,
                    'signed_in' => $a->isSignedIn(), 'last_seen_at' => $a->last_seen_at?->toIso8601String(), 'last_ip' => $a->last_ip, 'over' => $a->isOver()]) : [],
            'examinerAccountsEnd' => $exam->isPanelMarked() ? optional((new \App\Models\ExamPanelAccount(['exam_id' => $exam->id]))->setRelation('exam', $exam)->endsAt())->toIso8601String() : null,
            'sessions' => $exam->sessions->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'mode' => $s->mode, 'status' => $s->status, 'venue' => $s->venue?->name,
                'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                'candidates' => $s->candidates_count, 'attempts' => $s->attempts_count, 'is_practice' => $s->is_practice,
            ]),
            'approvals' => $exam->approvals->map(fn ($a) => ['step' => $a->step, 'decision' => $a->decision, 'comment' => $a->comment, 'at' => $a->created_at?->toDateTimeString(), 'user' => User::find($a->user_id)?->name]),
            'courses' => Course::orderBy('code')->get(['id', 'code', 'title']),
            'semesters' => Semester::with('academicSession:id,name')->latest('id')->get(['id', 'name', 'academic_session_id']),
            'topics' => Question::query()->when($exam->course_id, fn ($q) => $q->where('course_id', $exam->course_id))->whereNotNull('topic')->distinct()->orderBy('topic')->pluck('topic'),
            'staff' => User::where('user_type', 'staff')->orderBy('name')->limit(500)->get(['id', 'name', 'email']),
            'parent' => $exam->parent_exam_id ? Exam::find($exam->parent_exam_id, ['id', 'title']) : null,
            'followUps' => Exam::where('parent_exam_id', $exam->id)->get(['id', 'title', 'kind', 'status']),
            'anomalies' => \App\Models\AttemptAnomaly::with('attempt.user:id,name,identifier')->where('exam_id', $exam->id)->orderByDesc('score')->limit(100)->get()
                ->map(fn ($x) => ['id' => $x->id, 'attempt_id' => $x->attempt_id, 'score' => $x->score, 'reasons' => $x->reasons, 'review' => $x->review,
                    'candidate' => $x->attempt?->user?->only(['name', 'identifier']), 'session_id' => $x->attempt?->exam_session_id]),
        ]);
    }

    /** Run the statistical review now (it also runs by itself after each exam closes). */
    public function runAnomalies(Exam $exam, \App\Services\Analytics\AnomalyModel $model): \Illuminate\Http\RedirectResponse
    {
        $r = $model->run($exam);

        return back()->with('success', $r['skipped'] ?? "Reviewed {$r['attempts']} attempts; {$r['flagged']} look unusual and need a person to look.");
    }

    /** A person's decision on a statistical flag: clear it, or open an incident for full review. */
    public function reviewAnomaly(\Illuminate\Http\Request $request, \App\Models\AttemptAnomaly $anomaly): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:clear,refer']]);
        abort_unless($anomaly->review === 'open', 409, 'Already reviewed.');
        $anomaly->update(['review' => $data['action'] === 'clear' ? 'cleared' : 'referred', 'reviewed_by' => $request->user()->id]);
        if ($data['action'] === 'refer') {
            $attempt = $anomaly->attempt;
            \App\Models\Incident::create(['exam_session_id' => $attempt->exam_session_id, 'attempt_id' => $attempt->id, 'type' => 'malpractice', 'severity' => 'medium',
                'status' => 'open', 'source' => 'analytics', 'needs_human_review' => true, 'title' => 'Statistical review: unusual answer pattern',
                'description' => collect($anomaly->reasons)->map(fn ($r) => str_replace('_', ' ', $r['feature']).' (z = '.$r['z'].')')->implode('; ')
                    ."\nThis is a statistical signal only, not evidence of misconduct. Review the attempt, recordings and the candidate's account before deciding.",
                'opened_by' => $request->user()->id]);
        }
        app(\App\Services\Audit\AuditLogger::class)->record('anomaly.'.$anomaly->review, $anomaly->attempt);

        return back()->with('success', $data['action'] === 'clear' ? 'Cleared.' : 'Incident opened for review.');
    }

    /**
     * Resit or supplementary exam (Target 21, 206; LMS 77): a new draft exam with the same
     * structure, linked to the original so only students who failed (resit) or failed or
     * missed it (supplementary) can be added. The paper goes through approval again.
     */
    public function followUp(Request $request, Exam $exam): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:resit,supplementary']]);
        abort_if($exam->kind !== 'regular', 422, 'Create resits from the original exam.');

        $copy = DB::transaction(function () use ($exam, $data, $request) {
            $copy = Exam::create([
                ...$exam->only(['course_id', 'semester_id', 'type', 'category', 'instructions', 'total_mark', 'pass_mark', 'duration_minutes', 'max_attempts', 'score_policy', 'settings', 'proctoring', 'channels', 'marking_policy']),
                'title' => $exam->title.' — '.ucfirst($data['kind']), 'code' => $exam->code ? $exam->code.'-'.strtoupper(substr($data['kind'], 0, 3)) : null,
                'kind' => $data['kind'], 'parent_exam_id' => $exam->id, 'status' => 'draft', 'created_by' => $request->user()->id, 'version' => 1,
            ]);
            foreach ($exam->sections()->with('questions')->get() as $section) {
                $new = $copy->sections()->create($section->only(['title', 'instructions', 'position', 'blueprint', 'answer_any']));
                $new->questions()->attach($section->questions->mapWithKeys(fn ($q) => [$q->id => ['mark' => $q->pivot->mark, 'position' => $q->pivot->position]])->all());
            }
            foreach ($exam->criteria as $c) {
                $copy->criteria()->create($c->only(['text', 'allowed_scores', 'max_score', 'position']));
            }

            return $copy;
        });
        $this->audit->record('exam.follow_up_created', $copy, ['kind' => $data['kind'], 'parent' => $exam->id]);

        return redirect()->route('exams.show', $copy)->with('success', ucfirst($data['kind']).' created as a draft. Swap in fresh questions, then send it for approval.');
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        $data = $this->validateExam($request, $exam);
        $settings = $request->validate([
            'settings' => ['sometimes', 'array'],
            'proctoring' => ['sometimes', 'array'],
            'channels' => ['sometimes', 'array', 'min:1'],
            'channels.*' => ['in:web,desktop,seb'],
            'seb' => ['sometimes', 'array'],
            'seb.config_key' => ['nullable', 'string', 'max:128'],
            'seb.browser_exam_keys' => ['nullable', 'array', 'max:20'],
            'seb.quit_password' => ['nullable', 'string', 'max:64'],
        ]);

        $structural = ['type', 'duration_minutes', 'total_mark', 'max_attempts', 'score_policy'];
        if (! in_array($exam->status, ['draft', 'returned'], true) && array_diff_assoc(array_intersect_key($data, array_flip($structural)), $exam->only($structural))) {
            abort(422, 'This exam is approved. Reopen it for editing to change its structure.');
        }

        $exam->fill($data);
        if (isset($settings['settings'])) {
            $exam->settings = array_merge(ExamSettings::settings($settings['settings']), ['seb' => data_get($exam->settings, 'seb', [])]);
        }
        if (isset($settings['seb'])) {
            $exam->settings = array_merge($exam->settings ?? [], ['seb' => array_filter($settings['seb'], fn ($v) => $v !== null)]);
        }
        if (isset($settings['proctoring'])) {
            $exam->proctoring = ExamSettings::proctoring($settings['proctoring']);
        }
        if (isset($settings['channels'])) {
            $exam->channels = array_values(array_unique($settings['channels']));
        }
        $dirty = array_keys($exam->getDirty());
        $exam->save();
        $this->audit->record('exam.updated', $exam, ['fields' => $dirty]);

        return back()->with('success', 'Saved.');
    }

    // ── Paper: sections, fixed questions, blueprint rules ──────────────────────

    public function storeSection(Request $request, Exam $exam): RedirectResponse
    {
        $this->assertEditable($exam);
        $data = $request->validate(['title' => ['required', 'string', 'max:190'], 'instructions' => ['nullable', 'string', 'max:5000'], 'answer_any' => ['nullable', 'integer', 'min:1']]);
        $exam->sections()->create($data + ['position' => $exam->sections()->count()]);

        return back();
    }

    public function updateSection(Request $request, Exam $exam, ExamSection $section): RedirectResponse
    {
        $this->assertEditable($exam);
        abort_unless($section->exam_id === $exam->id, 404);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:190'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'answer_any' => ['nullable', 'integer', 'min:1'],
            'blueprint' => ['sometimes', 'array', 'max:50'],
            'blueprint.*.count' => ['required_without:blueprint.*.draw', 'integer', 'min:1', 'max:500'],
            'blueprint.*.draw' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'blueprint.*.topic' => ['nullable', 'string', 'max:190'],
            'blueprint.*.difficulty' => ['nullable', 'in:easy,medium,hard'],
            'blueprint.*.type' => ['nullable', Rule::in(Question::TYPES)],
            'blueprint.*.mark' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $section->update($data);

        return back()->with('success', 'Section saved.');
    }

    public function destroySection(Exam $exam, ExamSection $section): RedirectResponse
    {
        $this->assertEditable($exam);
        abort_unless($section->exam_id === $exam->id, 404);
        $section->delete();

        return back();
    }

    public function attachQuestions(Request $request, Exam $exam, ExamSection $section): RedirectResponse
    {
        $this->assertEditable($exam);
        abort_unless($section->exam_id === $exam->id, 404);
        $data = $request->validate(['question_ids' => ['required', 'array', 'max:500'], 'question_ids.*' => ['integer', Rule::exists('questions', 'id')->where('tenant_id', tenant_id())]]);
        $start = $section->questions()->count();
        $sync = [];
        foreach (array_values($data['question_ids']) as $i => $id) {
            $sync[$id] = ['position' => $start + $i];
        }
        $section->questions()->syncWithoutDetaching($sync);

        return back()->with('success', count($sync).' questions added.');
    }

    public function detachQuestion(Exam $exam, ExamSection $section, Question $question): RedirectResponse
    {
        $this->assertEditable($exam);
        abort_unless($section->exam_id === $exam->id, 404);
        $section->questions()->detach($question->id);

        return back();
    }

    /** JSON search of the question bank for the "add questions" picker. */
    public function searchQuestions(Request $request, Exam $exam)
    {
        return Question::query()->with('options')
            ->when($request->boolean('course_only', true) && $exam->course_id, fn ($q) => $q->where('course_id', $exam->course_id))
            ->when($request->string('topic')->toString(), fn ($q, $t) => $q->where('topic', $t))
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('type', $t))
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where('stem_html', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%'))
            ->whereIn('status', $exam->category === 'practice' ? ['approved', 'draft', 'in_review'] : ['approved'])
            ->latest('id')->limit(50)->get()
            ->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'stem_html' => $q->stem_html, 'mark' => (float) $q->mark, 'topic' => $q->topic, 'difficulty' => $q->difficulty, 'status' => $q->status]);
    }

    // ── Practical / viva criteria (legacy questions + score_values) ───────────

    public function saveCriteria(Request $request, Exam $exam): RedirectResponse
    {
        $this->assertEditable($exam);
        abort_unless($exam->isPanelMarked(), 422);
        $data = $request->validate([
            'criteria' => ['present', 'array', 'max:200'],
            'criteria.*.id' => ['nullable', 'integer'],
            'criteria.*.text' => ['required', 'string', 'max:500'],
            'criteria.*.allowed_scores' => ['nullable', 'string', 'max:100'], // "0,0.25,0.5" like the legacy portal
            'criteria.*.max_score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        DB::transaction(function () use ($exam, $data) {
            $keep = [];
            foreach ($data['criteria'] as $i => $c) {
                $allowed = isset($c['allowed_scores']) && trim($c['allowed_scores']) !== ''
                    ? array_values(array_unique(array_map(fn ($v) => (float) trim($v), explode(',', $c['allowed_scores']))))
                    : null;
                if ($allowed) {
                    sort($allowed);
                }
                $max = $allowed ? max($allowed) : (float) ($c['max_score'] ?? 0);
                abort_if($max <= 0, 422, "Criterion \"{$c['text']}\" needs allowed scores or a maximum.");
                $row = MarkingCriterion::updateOrCreate(
                    ['id' => $c['id'] ?? null, 'exam_id' => $exam->id],
                    ['text' => $c['text'], 'allowed_scores' => $allowed, 'max_score' => $max, 'position' => $i]
                );
                $keep[] = $row->id;
            }
            $exam->criteria()->whereNotIn('id', $keep ?: [0])->delete();
        });

        return back()->with('success', 'Criteria saved.');
    }

    // ── People ────────────────────────────────────────────────────────────────

    public function assignExaminer(Request $request, Exam $exam): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', Rule::exists('users', 'id')->where('tenant_id', tenant_id())], 'role' => ['required', 'in:examiner,moderator,external']]);
        $exam->examiners()->syncWithoutDetaching([$data['user_id'] => ['role' => $data['role']]]);
        $this->audit->record('exam.examiner_assigned', $exam, $data);

        return back();
    }

    public function removeExaminer(Exam $exam, User $user): RedirectResponse
    {
        $exam->examiners()->detach($user->id);

        return back();
    }

    // ── Workflow ──────────────────────────────────────────────────────────────

    public function transition(Request $request, Exam $exam): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:submit,approve,return,reopen,close,archive'], 'comment' => ['nullable', 'string', 'max:2000']]);
        $user = $request->user();

        match ($data['action']) {
            'submit' => $this->workflow->submitForApproval($exam, $user),
            'approve' => $this->authorizeApprove($user) && $this->workflow->approve($exam, $user, $data['comment'] ?? null),
            'return' => $this->authorizeApprove($user) && $this->workflow->returnForChanges($exam, $user, $data['comment'] ?? 'Returned for changes'),
            'reopen' => $this->workflow->reopenForEditing($exam, $user),
            'close' => $exam->update(['status' => 'closed']),
            'archive' => $exam->update(['status' => 'archived']),
        };

        return back()->with('success', 'Exam '.$data['action'].' done.');
    }

    private function authorizeApprove(User $user): bool
    {
        abort_unless($user->can('questions.moderate') || $user->can('exams.manage'), 403);

        return true;
    }

    private function assertEditable(Exam $exam): void
    {
        abort_unless(in_array($exam->status, ['draft', 'returned'], true), 422, 'This exam is approved or live. Reopen it for editing first.');
    }

    private function validateExam(Request $request, ?Exam $exam = null): array
    {
        return $request->validate([
            'title' => [$exam ? 'sometimes' : 'required', 'string', 'max:190'],
            'code' => ['nullable', 'string', 'max:40'],
            'type' => [$exam ? 'sometimes' : 'required', Rule::in(Exam::TYPES)],
            'category' => ['sometimes', 'in:exam,test,quiz,practice,mock'],
            'course_id' => ['nullable', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
            'semester_id' => ['nullable', Rule::exists('semesters', 'id')->where('tenant_id', tenant_id())],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'total_mark' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'pass_mark' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'score_policy' => ['sometimes', 'in:highest,first,last,average'],
            'marking_policy' => ['sometimes', 'in:average,highest,first,median'],
        ]);
    }
}
