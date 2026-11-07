<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\CourseGrade;
use App\Models\CourseOffering;
use App\Models\GradeCategory;
use App\Models\GradeItem;
use App\Services\Lms\GradebookService;
use App\Services\Lms\LmsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradebookController extends Controller
{
    public function __construct(private LmsAccess $access, private GradebookService $gradebook) {}

    public function show(Request $request, CourseOffering $offering): Response
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $this->gradebook->syncAll($offering);
        $this->gradebook->compute($offering);

        $items = GradeItem::where('course_offering_id', $offering->id)->orderBy('grade_category_id')->orderBy('id')->get();
        $students = DB::table('enrolments')->join('users', 'users.id', '=', 'enrolments.user_id')->where('course_offering_id', $offering->id)
            ->whereIn('enrolments.status', ['active', 'completed'])->orderBy('users.name')->get(['users.id', 'users.name', 'users.identifier']);
        $entries = DB::table('grade_entries')->whereIn('grade_item_id', $items->pluck('id'))->get()->groupBy('user_id')
            ->map(fn ($g) => $g->mapWithKeys(fn ($e) => [$e->grade_item_id => ['score' => $e->score === null ? null : (float) $e->score, 'overridden' => (bool) $e->overridden]]));
        $grades = CourseGrade::where('course_offering_id', $offering->id)->get()->keyBy('user_id');

        return Inertia::render('learn/gradebook', [
            'offering' => ['id' => $offering->id, 'label' => $offering->label(), 'title' => $offering->course->title],
            'categories' => GradeCategory::where('course_offering_id', $offering->id)->orderBy('position')->get(['id', 'name', 'weight', 'aggregation', 'best_n']),
            'items' => $items->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'source' => $i->source, 'max' => $i->max_score, 'category' => $i->grade_category_id]),
            'rows' => $students->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'identifier' => $s->identifier, 'entries' => $entries[$s->id] ?? (object) [],
                'total' => $grades[$s->id]->total ?? null, 'grade' => $grades[$s->id]->grade ?? null, 'status' => $grades[$s->id]->status ?? 'draft']),
            'canGrade' => $this->access->canGrade($request->user(), $offering),
            'canApprove' => $this->access->oversees($request->user()) && ! $this->access->role($request->user(), $offering),
            'stats' => $this->stats($grades),
            'participation' => app(\App\Services\Lms\Participation::class)->scores($offering),
        ]);
    }

    public function categories(Request $request, CourseOffering $offering): RedirectResponse
    {
        abort_unless($this->access->canGrade($request->user(), $offering), 403);
        $data = $request->validate(['categories' => ['required', 'array', 'min:1', 'max:10'], 'categories.*.id' => ['nullable', 'integer'],
            'categories.*.name' => ['required', 'string', 'max:100'], 'categories.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'categories.*.aggregation' => ['nullable', 'in:mean,sum,best_n'], 'categories.*.best_n' => ['nullable', 'integer', 'min:1']]);
        $this->gradebook->saveCategories($offering, $data['categories']);

        return back()->with('success', 'Weighting saved.');
    }

    public function storeItem(Request $request, CourseOffering $offering): RedirectResponse
    {
        abort_unless($this->access->canGrade($request->user(), $offering), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:190'], 'max_score' => ['required', 'numeric', 'min:1', 'max:1000'],
            'grade_category_id' => ['required', 'integer'], 'source' => ['nullable', 'in:manual,attendance']]);
        abort_unless(GradeCategory::where('course_offering_id', $offering->id)->whereKey($data['grade_category_id'])->exists(), 422);
        GradeItem::create($data + ['course_offering_id' => $offering->id, 'source' => $data['source'] ?? 'manual', 'source_id' => ($data['source'] ?? null) === 'attendance' ? 0 : null]);

        return back()->with('success', 'Column added.');
    }

    public function entry(Request $request, GradeItem $gradeItem): RedirectResponse
    {
        abort_unless($this->access->canGrade($request->user(), CourseOffering::findOrFail($gradeItem->course_offering_id)), 403);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'score' => ['nullable', 'numeric']]);
        abort_unless(DB::table('enrolments')->where('course_offering_id', $gradeItem->course_offering_id)->where('user_id', $data['user_id'])->exists(), 422);
        $this->gradebook->setManual($gradeItem, $data['user_id'], isset($data['score']) ? (float) $data['score'] : null, $request->user());

        return back();
    }

    /** approve: HOD/Dean (not the course's own teacher — separation of duties); release: course coordinator/lecturer after approval. */
    public function transition(Request $request, CourseOffering $offering): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:approve,release']])['action'];
        if ($action === 'approve') {
            abort_unless($this->access->oversees($request->user()) && ! $this->access->role($request->user(), $offering), 403, 'Grades are approved by the HOD or Dean.');
            $n = $this->gradebook->approve($offering, $request->user());
        } else {
            abort_unless($this->access->canGrade($request->user(), $offering), 403);
            $n = $this->gradebook->release($offering, $request->user());
        }

        return back()->with('success', ucfirst($action)."d $n results.");
    }

    public function export(Request $request, CourseOffering $offering): StreamedResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $items = GradeItem::where('course_offering_id', $offering->id)->orderBy('grade_category_id')->orderBy('id')->get();
        $label = preg_replace('/[^A-Za-z0-9]+/', '-', $offering->label());

        return response()->streamDownload(function () use ($offering, $items) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['Matric/ID', 'Name'], $items->map(fn ($i) => "{$i->title} (/{$i->max_score})")->all(), ['Total', 'Grade', 'Status']));
            $entries = DB::table('grade_entries')->whereIn('grade_item_id', $items->pluck('id'))->get()->groupBy('user_id');
            DB::table('course_grades')->join('users', 'users.id', '=', 'course_grades.user_id')->where('course_offering_id', $offering->id)
                ->orderBy('users.name')->select('users.id', 'users.name', 'users.identifier', 'course_grades.total', 'course_grades.grade', 'course_grades.status')
                ->chunk(500, function ($rows) use ($out, $items, $entries) {
                    foreach ($rows as $r) {
                        $mine = collect($entries[$r->id] ?? [])->pluck('score', 'grade_item_id');
                        // Neutralise spreadsheet formulas in names (CSV injection)
                        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v;
                        fputcsv($out, array_merge([$safe($r->identifier), $safe($r->name)], $items->map(fn ($i) => $mine[$i->id] ?? '')->all(), [$r->total, $r->grade, $r->status]));
                    }
                });
            fclose($out);
        }, "gradebook-$label.csv", ['Content-Type' => 'text/csv']);
    }

    /** Students: their released course results, with breakdown (LMS 48, 153). */
    public function mine(Request $request): Response
    {
        $grades = CourseGrade::where('user_id', $request->user()->id)->where('status', 'released')->with('user')->latest('released_at')->get();
        $offerings = CourseOffering::with('course', 'semester.session')->whereIn('id', $grades->pluck('course_offering_id'))->get()->keyBy('id');

        return Inertia::render('learn/grades', ['grades' => $grades->map(fn ($g) => [
            'course' => $offerings[$g->course_offering_id]->course->code, 'title' => $offerings[$g->course_offering_id]->course->title,
            'units' => $offerings[$g->course_offering_id]->course->credit_units, 'semester' => $offerings[$g->course_offering_id]->label(),
            'total' => $g->total, 'grade' => $g->grade, 'breakdown' => $g->breakdown, 'released_at' => $g->released_at,
        ]), 'cgpa' => app(\App\Services\Documents\AcademicRecord::class)->record($request->user())['cgpa']]);
    }

    private function stats($grades): array
    {
        $totals = $grades->pluck('total')->filter(fn ($t) => $t !== null)->map(fn ($t) => (float) $t);

        return ['count' => $totals->count(), 'mean' => $totals->count() ? round($totals->avg(), 1) : null,
            'pass_rate' => $totals->count() ? round($grades->where('grade', '!=', 'F')->count() / $totals->count() * 100) : null,
            'distribution' => $grades->groupBy('grade')->map->count()];
    }
}
