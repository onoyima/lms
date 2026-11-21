<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamGroup;
use App\Models\ExamLink;
use App\Models\ExamSession;
use App\Models\Programme;
use App\Models\Semester;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\CandidateRoster;
use App\Services\Exams\ExamPresets;
use App\Support\Exams\ExamSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An exam with its papers, set up from one place: create "Nursing Professional Exam" with an
 * objective, a theory, a practical and a viva paper; then for each paper add the questions (or
 * marking criteria), the students, the examiners and the time. Each paper is an ordinary exam,
 * so marking, proctoring, approval and results work exactly as before.
 */
class ExamGroupController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function create(Request $request): Response
    {
        return Inertia::render('exams/new', [
            'courses' => Course::orderBy('code')->get(['id', 'code', 'title']),
            'semesters' => Semester::with('academicSession:id,name')->orderByDesc('is_current')->orderByDesc('id')->limit(12)->get(['id', 'name', 'academic_session_id', 'is_current'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => trim(($s->academicSession?->name ?? '').' '.$s->name), 'current' => (bool) $s->is_current]),
            'venues' => Venue::orderBy('name')->get(['id', 'name']),
            'papers' => collect(ExamGroup::PAPERS)->map(fn ($label, $type) => ['type' => $type, 'label' => $label])->values(),
            'defaults' => ['course_id' => $request->integer('course') ?: null],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:5000'],
            'course_id' => ['nullable', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
            'semester_id' => ['nullable', Rule::exists('semesters', 'id')->where('tenant_id', tenant_id())],
            'papers' => ['required', 'array', 'min:1', 'max:8'],
            'papers.*.type' => ['required', Rule::in(array_keys(ExamGroup::PAPERS))],
            'papers.*.title' => ['nullable', 'string', 'max:120'],
            'papers.*.duration' => ['required', 'integer', 'min:1', 'max:600'],
            'papers.*.total_mark' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'papers.*.starts_at' => ['nullable', 'date'],
            'papers.*.mode' => ['nullable', 'in:hall,remote'],
            'papers.*.venue_id' => ['nullable', Rule::exists('venues', 'id')->where('tenant_id', tenant_id())],
            'papers.*.protection' => ['nullable', Rule::in(ExamPresets::PROTECTION)],
            'papers.*.category' => ['nullable', 'in:exam,test,quiz,practice,mock,promotion'],
            'papers.*.course_id' => ['nullable', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
        ]);
        $user = $request->user();

        $group = DB::transaction(function () use ($data, $user) {
            $group = ExamGroup::create([
                'title' => $data['title'], 'code' => $data['code'] ?? null, 'description' => $data['description'] ?? null,
                'course_id' => $data['course_id'] ?? null, 'semester_id' => $data['semester_id'] ?? null, 'created_by' => $user->id,
            ]);
            foreach ($data['papers'] as $p) {
                $this->makePaper($group, $p, $user->id);
            }

            return $group;
        });
        $this->audit->record('exam_group.created', $group, ['papers' => array_column($data['papers'], 'type')]);

        return redirect()->route('exam-groups.show', $group)->with('success', 'Category created. Work through each exam below: questions, students, examiners and time.');
    }

    /** One paper: an ordinary exam with the group's course, a first section or criterion, rules and (if a time was given) its sitting. */
    private function makePaper(ExamGroup $group, array $p, int $userId): Exam
    {
        $type = $p['type'];
        $label = trim((string) ($p['title'] ?? '')) ?: ExamGroup::PAPERS[$type];
        $proctoring = ExamSettings::defaultsFor($type, $group->course_id);
        if (! empty($p['protection'])) {
            $proctoring = ExamSettings::proctoring(array_replace($proctoring, ExamPresets::protection($p['protection'])));
        }
        $exam = Exam::create([
            'exam_group_id' => $group->id, 'title' => $group->title.' · '.$label, 'code' => $group->code ? $group->code.'-'.strtoupper(substr($type, 0, 3)) : null,
            'type' => $type, 'category' => $p['category'] ?? 'exam', 'course_id' => ($p['course_id'] ?? null) ?: $group->course_id,
            'semester_id' => ($p['semester_id'] ?? null) ?: $group->semester_id,
            'status' => 'draft', 'version' => 1, 'duration_minutes' => (int) $p['duration'], 'total_mark' => $p['total_mark'] ?? 100,
            'max_attempts' => 1, 'created_by' => $userId, 'settings' => ExamSettings::SETTINGS, 'proctoring' => $proctoring,
            'channels' => ['web', 'desktop', 'seb'],
        ]);
        if ($exam->isOnScreen()) {
            $exam->sections()->create(['title' => $type === 'essay' ? 'Section A — Theory' : 'Section A', 'position' => 0]);
        } else {
            $exam->criteria()->create(['text' => match ($type) { 'viva' => 'General viva performance', 'research' => 'Research work and defence', default => 'Overall practical performance' },
                'allowed_scores' => null, 'max_score' => $exam->total_mark ?: 100, 'position' => 0]);
        }
        if (! empty($p['starts_at'])) {
            $start = Carbon::parse($p['starts_at']);
            $exam->sessions()->create([
                'name' => $label, 'mode' => $p['mode'] ?? ($exam->isOnScreen() ? 'remote' : 'hall'), 'venue_id' => $p['venue_id'] ?? null,
                'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes((int) $p['duration'] + 30),
                'late_entry_minutes' => 30, 'grace_seconds' => 30, 'status' => 'scheduled',
            ]);
        }

        return $exam;
    }

    public function addPaper(Request $request, ExamGroup $group): RedirectResponse
    {
        $p = $request->validate([
            'type' => ['required', Rule::in(array_keys(ExamGroup::PAPERS))],
            'title' => ['nullable', 'string', 'max:120'],
            'duration' => ['required', 'integer', 'min:1', 'max:600'],
            'total_mark' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'mode' => ['nullable', 'in:hall,remote'],
            'venue_id' => ['nullable', Rule::exists('venues', 'id')->where('tenant_id', tenant_id())],
            'protection' => ['nullable', Rule::in(ExamPresets::PROTECTION)],
            'category' => ['nullable', 'in:exam,test,quiz,practice,mock,promotion'],
            'course_id' => ['nullable', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
        ]);
        $exam = $this->makePaper($group, $p, $request->user()->id);
        $this->audit->record('exam_group.paper_added', $group, ['exam' => $exam->id, 'type' => $exam->type]);

        return back()->with('success', $this->paperName($group, $exam).' added to the category.');
    }

    public function update(Request $request, ExamGroup $group): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $old = $group->title;
        $group->update($data);
        if ($old !== $group->title) {
            // keep the papers' names in step ("Nursing Professional Exam · Theory")
            foreach ($group->papers as $paper) {
                if (str_starts_with($paper->title, $old.' · ')) {
                    $paper->update(['title' => $group->title.' · '.substr($paper->title, strlen($old.' · '))]);
                }
            }
        }

        return back()->with('success', 'Saved.');
    }

    /** Overview: each paper with what is done and what is still needed, in the order it is usually done. */
    public function show(ExamGroup $group): Response
    {
        $group->load('course:id,code,title', 'semester:id,name');
        $papers = $group->papers()->with(['sessions' => fn ($q) => $q->orderBy('starts_at'), 'sessions.venue:id,name'])
            ->withCount(['criteria', 'examiners'])->get();
        $ids = $papers->pluck('id');
        $fixed = DB::table('exam_section_questions as q')->join('exam_sections as s', 's.id', '=', 'q.exam_section_id')->whereIn('s.exam_id', $ids)
            ->groupBy('s.exam_id')->pluck(DB::raw('count(*)'), 's.exam_id');
        $drawn = DB::table('exam_sections')->whereIn('exam_id', $ids)->get(['exam_id', 'blueprint'])->groupBy('exam_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => collect(json_decode((string) $r->blueprint, true) ?: [])->sum(fn ($b) => (int) ($b['count'] ?? 0))));
        $candidates = DB::table('exam_candidates as c')->join('exam_sessions as s', 's.id', '=', 'c.exam_session_id')->whereIn('s.exam_id', $ids)
            ->groupBy('s.exam_id')->pluck(DB::raw('count(*)'), 's.exam_id');
        $accounts = DB::table('exam_panel_accounts')->whereIn('exam_id', $ids)->groupBy('exam_id')->pluck(DB::raw('count(*)'), 'exam_id');
        $results = DB::table('results')->whereIn('exam_id', $ids)->groupBy('exam_id')->pluck(DB::raw('count(*)'), 'exam_id');
        $attempts = DB::table('attempts')->whereIn('exam_id', $ids)->select('exam_id', 'status', DB::raw('count(*) as n'))->groupBy('exam_id', 'status')->get()->groupBy('exam_id');

        $papers->load('course:id,code');
        $rows = $papers->map(function (Exam $e) use ($fixed, $drawn, $candidates, $accounts, $attempts, $results, $group) {
            $questions = (int) ($fixed[$e->id] ?? 0) + (int) ($drawn[$e->id] ?? 0);
            $panel = $e->isPanelMarked();
            $needsExaminers = in_array($e->type, ['essay', 'mixed', 'practical', 'viva', 'research'], true);
            $examiners = (int) $e->examiners_count + (int) ($accounts[$e->id] ?? 0);
            $first = $e->sessions->first();
            $a = $attempts->get($e->id, collect())->pluck('n', 'status');
            $steps = [
                ['key' => 'paper', 'label' => $panel ? 'Marking criteria' : 'Questions', 'done' => $panel ? $e->criteria_count > 0 : $questions > 0,
                    'detail' => $panel ? $e->criteria_count.' '.($e->criteria_count === 1 ? 'criterion' : 'criteria') : ($questions ? $questions.' '.($questions === 1 ? 'question' : 'questions') : 'None yet')],
                ['key' => 'sittings', 'label' => 'Date and place', 'done' => (bool) $first,
                    'detail' => $first ? $first->starts_at->format('D j M, H:i').' · '.($first->venue?->name ?? ($first->mode === 'remote' ? 'online' : 'hall')) : 'Not set'],
                ['key' => 'students', 'label' => 'Students', 'done' => (int) ($candidates[$e->id] ?? 0) > 0, 'detail' => (int) ($candidates[$e->id] ?? 0).' assigned'],
                ['key' => 'examiners', 'label' => 'Examiners', 'done' => ! $needsExaminers || $examiners > 0, 'optional' => ! $needsExaminers,
                    'detail' => $needsExaminers ? ($examiners ? $examiners.' assigned' : 'None yet') : 'Marked automatically'],
                ['key' => 'rules', 'label' => 'Rules and monitoring', 'done' => true, 'detail' => $this->protectionLabel((array) $e->proctoring, $e->isOnScreen())],
                \App\Support\Policy::get('exam_paper_approval')
                    ? ['key' => 'approval', 'label' => 'Approval', 'done' => in_array($e->status, ['scheduled', 'live', 'closed', 'results_published'], true),
                        'detail' => match ($e->status) { 'draft', 'returned' => 'Not sent yet', 'submitted' => 'Waiting for a second person', default => 'Approved' }]
                    : ['key' => 'approval', 'label' => 'Published', 'done' => in_array($e->status, ['scheduled', 'live', 'closed', 'results_published'], true),
                        'detail' => in_array($e->status, ['draft', 'returned', 'submitted'], true) ? 'Not yet' : 'Published'],
            ];

            return [
                'id' => $e->id, 'type' => $e->type, 'label' => $this->paperName($group, $e), 'kind_label' => ExamGroup::PAPERS[$e->type] ?? ucfirst($e->type),
                'category' => $e->category, 'course' => $e->course_id && $e->course_id !== $group->course_id ? $e->course?->code : null, 'title' => $e->title, 'status' => $e->status,
                'results' => (int) ($results[$e->id] ?? 0),
                'duration' => $e->duration_minutes, 'total_mark' => (float) $e->total_mark, 'panel' => $panel,
                'steps' => $steps, 'ready' => collect($steps)->every(fn ($s) => $s['done']),
                'next' => collect($steps)->first(fn ($s) => ! $s['done'])['key'] ?? null,
                'sittings' => $e->sessions->map(fn (ExamSession $s) => ['id' => $s->id, 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String()]),
                'progress' => ['writing' => (int) ($a['in_progress'] ?? 0), 'submitted' => (int) (($a['submitted'] ?? 0) + ($a['auto_submitted'] ?? 0)), 'candidates' => (int) ($candidates[$e->id] ?? 0)],
            ];
        });

        return Inertia::render('exams/group', [
            'needsApproval' => (bool) \App\Support\Policy::get('exam_paper_approval'),
            'group' => ['id' => $group->id, 'title' => $group->title, 'code' => $group->code, 'description' => $group->description,
                'course' => $group->course ? trim($group->course->code.' — '.$group->course->title, ' —') : null, 'course_id' => $group->course_id, 'semester' => $group->semester?->name],
            'papers' => $rows,
            'students' => [
                'count' => DB::table('exam_group_students')->where('exam_group_id', $group->id)->count(),
                'list' => DB::table('exam_group_students as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.exam_group_id', $group->id)
                    ->orderBy('u.name')->limit(300)->get(['u.id', 'u.name', 'u.identifier']),
            ],
            'courses' => Course::orderBy('code')->get(['id', 'code', 'title']),
            'canExport' => request()->user()->can('results.approve') || request()->user()->can('results.publish'),
            'paperTypes' => collect(ExamGroup::PAPERS)->map(fn ($label, $type) => ['type' => $type, 'label' => $label])->values(),
            'venues' => Venue::orderBy('name')->get(['id', 'name']),
            'programmes' => Programme::orderBy('name')->get(['id', 'name']),
            'levels' => DB::table('student_profiles')->whereNotNull('level')->distinct()->orderBy('level')->pluck('level'),
        ]);
    }

    /**
     * Students for the whole category (they sit every exam in it, also exams added later) or for chosen
     * exams only, from the student records: a course's class list, a programme and level, or matric
     * numbers / e-mails. Each exam's sittings get them; an exam without a date gets them when it has one.
     */
    public function assignStudents(Request $request, ExamGroup $group, CandidateRoster $roster): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['nullable', 'in:category,exams'],
            'papers' => ['nullable', 'required_unless:target,category', 'array'],
            'papers.*' => ['integer'],
            'source' => ['required', 'in:course,programme,list,upload,match,ids'],
            'list_token' => ['nullable', 'required_if:source,upload', 'string', 'size:32'],
            'create_missing' => ['boolean'],
            'password_rule' => ['nullable', 'in:file_or_matric,matric,surname'],
            'identifier_prefix' => ['nullable', 'string', 'max:60'],
            'email_domain' => ['nullable', 'string', 'max:120'],
            'user_ids' => ['nullable', 'required_if:source,ids', 'array', 'max:5000'], 'user_ids.*' => ['integer'],
            'programme_id' => ['nullable', 'required_if:source,programme', Rule::exists('programmes', 'id')->where('tenant_id', tenant_id())],
            'level' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'identifiers' => ['nullable', 'required_if:source,list', 'string', 'max:200000'],
        ]);
        $whole = ($data['target'] ?? 'exams') === 'category';
        $papers = $whole ? $group->papers()->with('sessions')->get() : $group->papers()->whereIn('id', $data['papers'] ?? [])->with('sessions')->get();
        $source = match ($data['source']) {
            'course' => ['from_enrolment' => true],
            'programme' => ['from_programme' => ['programme_id' => (int) $data['programme_id'], 'level' => $data['level'] ?? null]],
            'upload' => ['list_token' => $data['list_token'], 'create_missing' => (bool) ($data['create_missing'] ?? false), 'password_rule' => $data['password_rule'] ?? 'file_or_matric'],
            'match' => array_filter(['identifier_prefix' => $data['identifier_prefix'] ?? null, 'email_domain' => $data['email_domain'] ?? null]) ?: abort(422, 'Give a matric number prefix or an e-mail domain.'),
            'ids' => ['user_ids' => $data['user_ids']],
            default => ['identifiers' => $data['identifiers']],
        };
        // an uploaded list makes its new accounts once, then every paper uses the same people
        $source['audience'] = $papers->isNotEmpty() && $papers->every(fn ($p) => $p->category === 'promotion') ? 'staff' : 'student';
        if ($data['source'] === 'upload' || $data['source'] === 'match') {
            [$people, $listMissing] = $roster->resolve($source);
            $source = ['user_ids' => $people->pluck('id')->all()];
        }

        $missing = [];
        $kept = 0;
        if ($whole && $data['source'] !== 'course') {
            // the category's own list: every exam in it, now and later, takes these students
            [$users, $missing] = $roster->resolve($source);
            $now = now();
            $rows = $users->map(fn ($u) => ['tenant_id' => $group->tenant_id, 'exam_group_id' => $group->id, 'user_id' => $u->id, 'created_at' => $now, 'updated_at' => $now])->all();
            foreach (array_chunk($rows, 500) as $chunk) {
                $kept += DB::table('exam_group_students')->insertOrIgnore($chunk);
            }
            $source = ['user_ids' => $users->pluck('id')->all()];
        }

        $added = 0;
        $noSitting = [];
        $noCourse = [];
        foreach ($papers as $paper) {
            if ($paper->sessions->isEmpty()) {
                $noSitting[] = $this->paperName($group, $paper);

                continue;
            }
            if (isset($source['from_enrolment']) && ! ($paper->course_id && $paper->semester_id)) {
                $noCourse[] = $this->paperName($group, $paper);

                continue;
            }
            foreach ($paper->sessions as $session) {
                $r = $roster->add($session, $source);
                $added += $r['added'];
                $missing = array_merge($missing, $r['missing']);
            }
        }
        if (! $whole && $noSitting && $papers->count() === count($noSitting)) {
            throw ValidationException::withMessages(['papers' => 'Set a date for '.implode(', ', $noSitting).' first; students are added to a sitting.']);
        }
        if ($whole && $data['source'] === 'course' && $noSitting && $papers->count() === count($noSitting)) {
            throw ValidationException::withMessages(['papers' => 'Class lists are added exam by exam, to each exam\'s sitting: set the dates first.']);
        }

        $msg = $whole && $data['source'] !== 'course'
            ? $kept.' '.($kept === 1 ? 'student' : 'students').' added to the category; '.$added.' exam '.($added === 1 ? 'place' : 'places').' given.'
            : $added.' student places added.';
        if ($noSitting) {
            $msg .= $whole && $data['source'] !== 'course'
                ? ' '.implode(', ', $noSitting).' will get them when a date is set.'
                : ' Not added to '.implode(', ', $noSitting).': set a date first.';
        }
        if ($noCourse) {
            $msg .= ' '.implode(', ', $noCourse).' '.(count($noCourse) === 1 ? 'has' : 'have').' no course and semester, so no class list: add students another way.';
        }
        $missing = array_values(array_unique(array_merge($missing, $listMissing ?? [])));
        if ($missing) {
            $msg .= ' Not found: '.implode(', ', array_slice($missing, 0, 10)).(count($missing) > 10 ? '…' : '').'.';
        }
        $this->audit->record('exam_group.students_assigned', $group, ['target' => $whole ? 'category' : 'exams', 'papers' => $papers->pluck('id'), 'source' => $data['source'], 'added' => $added]);

        return back()->with('success', $msg);
    }

    /**
     * The category's combined results: one row per student with each exam's score, the total and the
     * percentage (PDF, CSV or Excel). "ABS" = assigned but did not sit; "Pending" = not fully marked yet.
     */
    public function report(Request $request, ExamGroup $group, string $format)
    {
        abort_unless($request->user()->can('results.approve') || $request->user()->can('results.publish'), 403);
        $papers = $group->papers()->with('sessions:id,exam_id,ends_at')->get();
        $ids = $papers->pluck('id');
        $results = \App\Models\Result::whereIn('exam_id', $ids)->get()->groupBy('user_id');
        $seats = DB::table('exam_candidates as c')->join('exam_sessions as s', 's.id', '=', 'c.exam_session_id')->whereIn('s.exam_id', $ids)
            ->get(['c.user_id', 's.exam_id', 's.ends_at'])->groupBy('user_id');
        $userIds = $seats->keys()->merge($results->keys())->merge(DB::table('exam_group_students')->where('exam_group_id', $group->id)->pluck('user_id'))->unique();
        $users = \App\Models\User::whereIn('id', $userIds)->orderBy('identifier')->orderBy('name')->get(['id', 'name', 'identifier']);

        $columns = ['Matric number', 'Name'];
        foreach ($papers as $paper) {
            $columns[] = $this->paperName($group, $paper).' (/'.rtrim(rtrim(number_format((float) $paper->total_mark, 2, '.', ''), '0'), '.').')';
        }
        array_push($columns, 'Total', 'Out of', '%', 'Exams sat');

        $rows = [];
        foreach ($users as $u) {
            $row = [$u->identifier, $u->name];
            $total = 0.0;
            $outOf = 0.0;
            $sat = 0;
            foreach ($papers as $paper) {
                $r = $results->get($u->id)?->firstWhere('exam_id', $paper->id);
                $seat = $seats->get($u->id)?->firstWhere('exam_id', $paper->id);
                if ($r && $r->final_score !== null && $r->status !== 'pending') {
                    $row[] = (float) $r->final_score;
                    $total += (float) $r->final_score;
                    $outOf += (float) ($paper->total_mark ?: $r->max_score);
                    $sat++;
                } elseif ($r) {
                    $row[] = 'Pending';
                    $sat++;
                } elseif ($seat) {
                    $ended = $seat->ends_at && \Illuminate\Support\Carbon::parse($seat->ends_at)->isPast();
                    $row[] = $ended ? 'ABS' : '';
                    $outOf += $ended ? (float) $paper->total_mark : 0;
                } else {
                    $row[] = '—'; // not taking this exam
                }
            }
            array_push($row, round($total, 2), round($outOf, 2), $outOf > 0 ? round($total / $outOf * 100, 1) : '', $sat.'/'.$papers->count());
            $rows[] = $row;
        }

        $this->audit->record('exam_group.report_exported', $group, ['format' => $format, 'rows' => count($rows)]);
        $exporter = app(\App\Services\Reports\Exporter::class);
        $name = 'category-'.$group->id.'-results';

        if ($format === 'json') {
            return response()->json(['category' => ['id' => $group->id, 'title' => $group->title, 'code' => $group->code],
                'exams' => $papers->map(fn ($p) => ['id' => $p->id, 'name' => $this->paperName($group, $p), 'type' => $p->type, 'total_mark' => (float) $p->total_mark])->values(),
                'students' => array_map(fn ($r) => array_combine($columns, $r), $rows), 'exported_at' => now()->toIso8601String()], 200,
                ['Content-Disposition' => 'attachment; filename="'.$name.'.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return match ($format) {
            'xlsx' => $exporter->xlsx($name, 'Results', $columns, $rows),
            'pdf' => $exporter->pdf($name, 'pdf.report', ['title' => $group->title.' — combined results', 'columns' => $columns, 'rows' => $rows, 'truncated' => false,
                'subtitle' => $papers->count().' exams · '.count($rows).' students · ABS = assigned but did not sit · '.now()->format('j M Y H:i'),
                'footer' => 'Confidential — '.$request->user()->name], $papers->count() > 3 ? 'landscape' : 'portrait'),
            default => $exporter->csv($name, $columns, $rows),
        };
    }

    /** Take students off the whole category (their places in exams already given stay until removed there). */
    public function removeStudent(ExamGroup $group, int $user): RedirectResponse
    {
        DB::table('exam_group_students')->where('exam_group_id', $group->id)->where('user_id', $user)->delete();
        $this->audit->record('exam_group.student_removed', $group, ['user' => $user]);

        return back()->with('success', 'Removed from the category. Exams added from now on will not include this student.');
    }

    private function paperName(ExamGroup $group, Exam $paper): string
    {
        return self::nameIn($group, $paper);
    }

    /** An exam's own name inside its category ("Nursing exam · Viva 2" → "Viva 2"). */
    public static function nameIn(ExamGroup $group, Exam $paper): string
    {
        return str_starts_with($paper->title, $group->title.' · ') ? substr($paper->title, strlen($group->title.' · ')) : (ExamGroup::PAPERS[$paper->type] ?? $paper->title);
    }

    /** Send every paper that is ready to the second person for approval, in one go. */
    public function submitAll(Request $request, ExamGroup $group, \App\Services\Exams\ExamWorkflow $workflow): RedirectResponse
    {
        $sent = 0;
        $approval = (bool) \App\Support\Policy::get('exam_paper_approval');
        foreach ($group->papers()->whereIn('status', ['draft', 'returned'])->get() as $paper) {
            try {
                $approval ? $workflow->submitForApproval($paper, $request->user()) : $workflow->publish($paper, $request->user());
                $sent++;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                // a paper without questions or criteria stays a draft; the overview shows why
            }
        }

        return back()->with('success', $sent ? "$sent ".($sent === 1 ? 'paper' : 'papers').($approval ? ' sent for approval.' : ' published.') : 'No paper is ready yet: add questions or criteria first.');
    }

    private function protectionLabel(array $p, bool $onScreen): string
    {
        if (! $onScreen) {
            return 'Scored by examiners';
        }

        return match (true) {
            ! empty($p['record_video']) || ! empty($p['record_screen']) => 'Strict: camera and screen recorded',
            ! empty($p['webcam']) => 'Standard: camera pictures and face checks',
            default => 'Light: tab and copy checks',
        };
    }

    /** Exams list helper: groups with their papers' statuses (used by the Exams page). */
    public static function listing(): array
    {
        return ExamGroup::query()->with(['papers:id,exam_group_id,type,status,title'])->latest('id')->limit(30)->get()
            ->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'code' => $g->code,
                'papers' => $g->papers->map(fn ($p) => ['id' => $p->id, 'label' => self::nameIn($g, $p), 'status' => $p->status])])->all();
    }

    /** Open link for one paper's sitting (kept from the quick-exam work): make it if missing, then go to its control page. */
    public function shareSitting(Request $request, ExamSession $session): RedirectResponse
    {
        ExamLink::firstOrCreate(['exam_session_id' => $session->id], ['token' => ExamLink::newToken(), 'audience' => 'roster', 'enabled' => true, 'created_by' => $request->user()->id]);

        return redirect()->route('exams.ready', $session);
    }
}
