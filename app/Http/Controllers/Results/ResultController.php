<?php

namespace App\Http\Controllers\Results;

use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\Result;
use App\Models\ResultChange;
use App\Services\Analytics\ExamAnalytics;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\ExamWorkflow;
use App\Services\Exams\Marker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Results, moderation, approval, publication, changes, appeals, analytics and exports (Target 59-69, 149-162, 192-197). */
class ResultController extends Controller
{
    public function __construct(private ExamWorkflow $workflow, private ExamAnalytics $analytics, private AuditLogger $audit) {}

    public function show(Request $request, Exam $exam, Marker $marker): Response
    {
        if ($request->boolean('recompute')) {
            foreach ($exam->isPanelMarked()
                ? \App\Models\CriterionMark::where('exam_id', $exam->id)->distinct()->pluck('user_id')
                : $exam->attempts()->distinct()->pluck('user_id') as $uid) {
                $marker->refreshResult($exam, $uid);
            }
        }
        $results = Result::with('user:id,name,identifier')->where('exam_id', $exam->id)
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('final_score')->paginate(100)->withQueryString()
            ->through(fn (Result $r) => [
                'id' => $r->id, 'name' => $r->user?->name, 'identifier' => $r->user?->identifier,
                'raw' => $r->raw_score, 'max' => $r->max_score, 'final' => $r->final_score, 'grade' => $r->grade,
                'status' => $r->status, 'locked' => $r->locked, 'withheld_reason' => $r->withheld_reason,
            ]);
        $stats = Result::where('exam_id', $exam->id)->selectRaw('COUNT(*) n, AVG(final_score) mean, MIN(final_score) min, MAX(final_score) max')->first();
        $grades = Result::where('exam_id', $exam->id)->selectRaw('grade, COUNT(*) n')->groupBy('grade')->pluck('n', 'grade');
        $byStatus = Result::where('exam_id', $exam->id)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('results/exam', [
            'exam' => $exam->only(['id', 'title', 'type', 'total_mark', 'pass_mark', 'status']),
            'results' => $results,
            'stats' => ['count' => (int) $stats->n, 'mean' => round((float) $stats->mean, 2), 'min' => (float) $stats->min, 'max' => (float) $stats->max, 'grades' => $grades, 'status' => $byStatus],
            'changes' => ResultChange::with('result.user:id,name')->whereHas('result', fn ($q) => $q->where('exam_id', $exam->id))->where('status', 'pending')->get()
                ->map(fn ($c) => ['id' => $c->id, 'candidate' => $c->result?->user?->name, 'from' => $c->from_score, 'to' => $c->to_score, 'reason' => $c->reason, 'requested_by' => \App\Models\User::find($c->requested_by)?->name]),
            'appeals' => Appeal::with('user:id,name')->whereHas('result', fn ($q) => $q->where('exam_id', $exam->id))->whereIn('status', ['submitted', 'reviewing'])->get()
                ->map(fn ($a) => ['id' => $a->id, 'candidate' => $a->user?->name, 'statement' => $a->statement, 'status' => $a->status]),
            'canApprove' => $request->user()->can('results.approve'),
            'canPublish' => $request->user()->can('results.publish'),
        ]);
    }

    public function transition(Request $request, Exam $exam): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'in:moderated,approved,published'], 'ids' => ['nullable', 'array'], 'comment' => ['nullable', 'string', 'max:2000']]);
        $perm = ['moderated' => 'results.mark', 'approved' => 'results.approve', 'published' => 'results.publish'][$data['to']];
        abort_unless($request->user()->can($perm), 403);
        $n = $this->workflow->moveResults($exam, $data['to'], $request->user(), $data['ids'] ?? null, $data['comment'] ?? null);

        return back()->with('success', "$n results marked {$data['to']}.");
    }

    public function withhold(Request $request, Result $result): RedirectResponse
    {
        abort_unless($request->user()->can('results.approve'), 403);
        $this->workflow->withhold($result, $request->user(), $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);

        return back();
    }

    public function requestChange(Request $request, Result $result): RedirectResponse
    {
        abort_unless($request->user()->can('results.mark') || $request->user()->can('results.approve'), 403);
        $data = $request->validate(['to' => ['required', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $this->workflow->requestChange($result, $request->user(), (float) $data['to'], $data['reason']);

        return back()->with('success', 'Change requested. A second officer must approve it.');
    }

    public function decideChange(Request $request, ResultChange $change): RedirectResponse
    {
        abort_unless($request->user()->can('results.approve'), 403);
        $this->workflow->decideChange($change, $request->user(), $request->validate(['approve' => ['required', 'boolean']])['approve']);

        return back();
    }

    public function decideAppeal(Request $request, Appeal $appeal): RedirectResponse
    {
        abort_unless($request->user()->can('results.approve'), 403);
        $data = $request->validate(['upheld' => ['required', 'boolean'], 'decision' => ['required', 'string', 'max:5000']]);
        $this->workflow->decideAppeal($appeal, $request->user(), $data['upheld'], $data['decision']);

        return back();
    }

    public function analytics(Exam $exam): Response
    {
        $sessions = $exam->sessions()->get();

        return Inertia::render('results/analytics', [
            'exam' => $exam->only(['id', 'title', 'type']),
            'items' => $exam->isPanelMarked() ? [] : $this->analytics->itemAnalysis($exam),
            'sessions' => $sessions->map(fn (ExamSession $s) => ['id' => $s->id, 'name' => $s->name ?: $s->starts_at->format('D j M H:i'), 'report' => $this->analytics->sessionReport($s), 'pairs' => $this->analytics->suspiciousPairs($s)]),
            'departments' => $this->analytics->performanceByDepartment($exam),
        ]);
    }

    /** CSV export; bulk exports are permission-checked and audited (Target 196, 213). */
    public function export(Request $request, Exam $exam)
    {
        abort_unless($request->user()->can('results.approve') || $request->user()->can('results.publish'), 403);
        $format = in_array($request->query('format'), ['xlsx', 'csv', 'pdf'], true) ? $request->query('format') : 'csv';
        $this->audit->record('results.exported', $exam, ['rows' => Result::where('exam_id', $exam->id)->count(), 'format' => $format]);
        $columns = ['Matric number', 'Name', 'Raw', 'Max', 'Final', 'Grade', 'Status'];
        $rows = Result::with('user:id,name,identifier')->where('exam_id', $exam->id)->orderBy('id')->get()
            ->map(fn ($r) => [$r->user?->identifier, $r->user?->name, $r->raw_score, $r->max_score, $r->final_score, $r->grade, $r->status])->all();
        $exporter = app(\App\Services\Reports\Exporter::class);

        return match ($format) {
            'xlsx' => $exporter->xlsx('results-'.$exam->id, 'Results', $columns, $rows),
            'pdf' => $exporter->pdf('results-'.$exam->id, 'pdf.report', ['title' => $exam->title.' — results', 'columns' => $columns, 'rows' => $rows, 'truncated' => false,
                'subtitle' => count($rows).' results · '.now()->format('j M Y H:i'), 'footer' => 'Confidential — '.$request->user()->name], 'portrait'),
            default => $exporter->csv('results-'.$exam->id, $columns, $rows),
        };
    }
}
