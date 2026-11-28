<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Enrolment;
use App\Services\Audit\AuditLogger;
use App\Services\Lms\CourseContentService;
use App\Services\Lms\LmsAccess;
use App\Services\Questions\RichTextSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function __construct(private LmsAccess $access, private CourseContentService $content) {}

    /** Student: registered courses with progress and what is due. Lecturer: courses they teach (LMS 26, 43, 46). */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $staff = $user->user_type !== 'student';
        $oversees = $staff && $this->access->oversees($user);
        $mineIds = DB::table('course_offering_staff')->where('user_id', $user->id)->pluck('course_offering_id');
        $semesters = DB::table('semesters as s')->join('academic_sessions as a', 'a.id', '=', 's.academic_session_id')
            ->orderByDesc('s.id')->limit(12)->get(['s.id', 'a.name as session', 's.name', 's.is_current'])
            ->map(fn ($r) => ['id' => $r->id, 'label' => $r->session.' '.$r->name, 'is_current' => (bool) $r->is_current]);
        $semester = $oversees ? ($request->integer('semester') ?: ($semesters->firstWhere('is_current', true)['id'] ?? null)) : null;
        $q = trim($request->string('q')->toString());

        // lecturers: the courses assigned to them; administrators and oversight roles: every course, with search
        $teaching = CourseOffering::with('course', 'semester')
            ->when(! $oversees, fn ($w) => $w->whereIn('id', $mineIds))
            ->when($oversees && $semester, fn ($w) => $w->where('semester_id', $semester))
            ->when($oversees && $q !== '', fn ($w) => $w->whereHas('course', fn ($c) => $c->where('code', 'like', '%'.addcslashes($q, '%_').'%')->orWhere('title', 'like', '%'.addcslashes($q, '%_').'%')))
            ->withCount(['enrolments as students' => fn ($w) => $w->where('status', 'active')])
            ->orderByDesc('semester_id')->limit($oversees ? 300 : 500)->get()
            ->sortBy(fn ($o) => [$mineIds->contains($o->id) ? 0 : 1, $o->course->code])->values()
            ->map(fn ($o) => ['id' => $o->id, 'code' => $o->course->code, 'title' => $o->course->title, 'semester' => $o->semesterLabel(), 'students' => $o->students,
                'role' => $this->access->role($user, $o) ?? ($oversees ? 'oversight' : null), 'to_mark' => DB::table('assignment_submissions')->join('course_items', 'course_items.id', '=', 'assignment_submissions.course_item_id')
                    ->where('course_items.course_offering_id', $o->id)->where('assignment_submissions.status', 'submitted')->count(),
                'lecturers' => DB::table('course_offering_staff as cs')->join('users as u', 'u.id', '=', 'cs.user_id')->where('cs.course_offering_id', $o->id)->pluck('u.name')]);

        // staff never get the student view: no registered courses, no "what is due" list
        $enrolled = $staff ? collect() : Enrolment::with('offering.course', 'offering.semester', 'offering.staff:id,name')->where('user_id', $user->id)->whereIn('status', ['active', 'completed'])
            ->orderByDesc('id')->get()->map(fn ($e) => ['id' => $e->offering->id, 'code' => $e->offering->course->code, 'title' => $e->offering->course->title,
                'lecturers' => $e->offering->staff->sortBy(fn ($s) => $s->pivot->role === 'coordinator' ? 0 : 1)->pluck('name')->values(),
                'semester' => $e->offering->semesterLabel(), 'status' => $e->status, 'category' => $e->category, 'progress' => $this->content->progress($e->offering, $user)]);

        $offeringIds = $enrolled->where('status', 'active')->pluck('id');
        $upcoming = $staff ? collect() : CourseItem::with('offering.course')->whereIn('course_offering_id', $offeringIds)
            ->whereIn('type', app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(tenant(), 'question_bank') ? ['assignment', 'quiz'] : ['assignment'])
            ->where('visible', true)->whereNotNull('due_at')->where('due_at', '>=', now()->subDay())->orderBy('due_at')->limit(12)->get()
            ->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'type' => $i->type, 'course' => $i->offering->course->code, 'due_at' => $i->due_at,
                'submitted' => $i->type === 'assignment' && DB::table('assignment_submissions')->where('course_item_id', $i->id)->where('user_id', $user->id)->where('status', '!=', 'draft')->exists()]);

        return Inertia::render('learn/courses', ['teaching' => $teaching, 'enrolled' => $enrolled, 'upcoming' => $upcoming,
            'staff' => $staff, 'oversees' => $oversees, 'filters' => ['q' => $q, 'semester' => $semester], 'semesters' => $oversees ? $semesters : []]);
    }

    public function show(Request $request, CourseOffering $offering): Response
    {
        $user = $request->user();
        $this->access->authorizeView($user, $offering);
        $teacher = $this->access->teaches($user, $offering);
        $completed = $teacher ? [] : $this->access->completedIds($user, $offering);
        $offering->load('course.department', 'semester', 'staff');
        // course quizzes run on the exam engine: without the exams module they are left out
        $quizzes = app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(tenant(), 'question_bank');
        $bookmarks = $teacher ? [] : \App\Models\CourseItemNote::where('user_id', $user->id)->where('bookmarked', true)
            ->whereIn('course_item_id', CourseItem::where('course_offering_id', $offering->id)->pluck('id'))->pluck('course_item_id')->all();

        $sections = CourseSection::where('course_offering_id', $offering->id)->orderBy('position')
            ->with(['items' => fn ($q) => $q->orderBy('position')->with('prerequisite:id,title')])->get()
            ->filter(fn ($s) => $teacher || ($s->visible && (! $s->release_at || $s->release_at->isPast())))
            ->map(fn ($s) => [
                'id' => $s->id, 'title' => $s->title, 'summary' => $s->summary, 'visible' => $s->visible, 'release_at' => $s->release_at,
                'items' => $s->items->map(function (CourseItem $i) use ($teacher, $completed, $s, $bookmarks, $quizzes) {
                    $i->setRelation('section', $s);
                    [$open, $why] = $teacher ? [true, null] : $this->access->availability($i, $completed);

                    return ($teacher || $why !== 'hidden') && ($quizzes || $i->type !== 'quiz') ? [
                        'id' => $i->id, 'type' => $i->type, 'title' => $i->title, 'visible' => $i->visible, 'release_at' => $i->release_at, 'due_at' => $i->due_at,
                        'open' => $open, 'locked_reason' => $why, 'done' => in_array($i->id, $completed, true), 'file_name' => $i->file_name,
                        'file_mime' => $i->file_mime, 'file_size' => $i->file_size, 'url' => in_array($i->type, ['video', 'link'], true) ? $i->url : null,
                        'requires_completion' => $i->requires_completion, 'bookmarked' => in_array($i->id, $bookmarks, true),
                        'prerequisite' => $i->prerequisite?->title, 'version' => $i->version,
                    ] : null;
                })->filter()->values(),
            ])->values();

        return Inertia::render('learn/course', [
            'offering' => ['id' => $offering->id, 'code' => $offering->course->code, 'title' => $offering->course->title, 'units' => $offering->course->credit_units,
                'semester' => $offering->semesterLabel(), 'outline_html' => $offering->outline_html, 'description' => $offering->course->description, 'review_status' => $offering->review_status,
                'staff' => $offering->staff->map(fn ($s) => ['name' => $s->name, 'email' => $s->email, 'role' => $s->pivot->role])],
            'sections' => $sections,
            'recordings' => \App\Models\ClassMeeting::where('course_offering_id', $offering->id)->where(fn ($q) => $q->whereNotNull('recording_url')->orWhereNotNull('recording_path'))
                ->when(! $teacher, fn ($q) => $q->where('recording_published', true))->orderByDesc('held_at')->limit(50)->get()
                ->map(fn ($m) => ['id' => $m->id, 'held_at' => $m->held_at, 'topic' => $m->topic, 'published' => (bool) $m->recording_published,
                    'url' => $m->recording_url ?: route('learn.meetings.recording', $m->id)]),
            'teacher' => $teacher,
            'rendered_at' => now()->getTimestampMs(),
            'canGrade' => $this->access->canGrade($user, $offering),
            'progress' => $teacher ? null : $this->content->progress($offering, $user),
            'previous' => $teacher ? CourseOffering::with('semester')->where('course_id', $offering->course_id)->where('id', '!=', $offering->id)->get()->map(fn ($o) => ['id' => $o->id, 'label' => $o->label()]) : [],
            'announcements' => DB::table('announcements')->where('scope', 'offering')->where('scope_id', $offering->id)->where('publish_at', '<=', now())
                ->orderByDesc('publish_at')->limit(5)->get(['id', 'title', 'body_html', 'publish_at', 'urgent']),
            'feedback' => app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(tenant(), 'course_feedback') ? CourseFeedbackController::forCourse($offering, $user, $teacher) : null,
            'assessments' => $this->assessments($offering, $user, $teacher, $completed, $quizzes),
            'quizzes' => $quizzes,
            'students' => $teacher ? DB::table('enrolments')->where('course_offering_id', $offering->id)->where('status', 'active')->count() : null,
            'schedule' => DB::table('class_schedules')->where('course_offering_id', $offering->id)->orderBy('weekday')->orderBy('starts_at')
                ->get(['weekday', 'starts_at', 'ends_at', 'kind', 'venue', 'group_label'])->map(fn ($r) => ['weekday' => (int) $r->weekday, 'starts_at' => substr($r->starts_at, 0, 5),
                    'ends_at' => substr($r->ends_at, 0, 5), 'kind' => $r->kind, 'venue' => $r->venue, 'group_label' => $r->group_label]),
        ]);
    }

    /**
     * Every assessment in the course in one list: assignments and quizzes, when they are due, and
     * for a student where they stand (not started, submitted, marked with the score); for the
     * lecturer how many have submitted and how many wait for marking.
     */
    private function assessments(CourseOffering $offering, \App\Models\User $user, bool $teacher, array $completed, bool $quizzes = true): array
    {
        $items = CourseItem::with('section')->where('course_offering_id', $offering->id)->whereIn('type', $quizzes ? ['assignment', 'quiz'] : ['assignment'])
            ->when(! $teacher, fn ($q) => $q->where('visible', true))->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderBy('position')->get();
        $enrolled = $teacher ? DB::table('enrolments')->where('course_offering_id', $offering->id)->where('status', 'active')->count() : 0;

        return $items->map(function (CourseItem $i) use ($user, $teacher, $completed, $enrolled) {
            [$open, $why] = $teacher ? [true, null] : $this->access->availability($i, $completed);
            if (! $teacher && $why === 'hidden') {
                return null;
            }
            $max = (float) ($i->settings['max_score'] ?? 0) ?: null;
            $row = ['id' => $i->id, 'type' => $i->type, 'title' => $i->title, 'section' => $i->section?->title, 'due_at' => $i->due_at, 'open' => $open, 'locked_reason' => $why, 'max' => $max];
            if ($teacher) {
                $subs = $i->type === 'assignment'
                    ? DB::table('assignment_submissions')->where('course_item_id', $i->id)->where('status', '!=', 'draft')->selectRaw('COUNT(DISTINCT user_id) as n, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as waiting', ['submitted'])->first()
                    : ($i->exam_id ? DB::table('results')->where('exam_id', $i->exam_id)->selectRaw('COUNT(*) as n, 0 as waiting')->first() : null);

                return $row + ['submitted' => (int) ($subs->n ?? 0), 'to_mark' => (int) ($subs->waiting ?? 0), 'enrolled' => $enrolled];
            }
            if ($i->type === 'assignment') {
                $s = DB::table('assignment_submissions')->where('course_item_id', $i->id)->where('user_id', $user->id)->where('status', '!=', 'draft')->orderByDesc('attempt')->first();
                $state = ! $s ? (($i->due_at && $i->due_at->isPast()) ? 'missed' : 'to_do') : ($s->released ? 'marked' : 'submitted');

                return $row + ['state' => $state, 'score' => $s && $s->released ? (float) $s->score : null, 'late' => (bool) ($s->is_late ?? false)];
            }
            $r = $i->exam_id ? DB::table('results')->where('exam_id', $i->exam_id)->where('user_id', $user->id)->first(['final_score', 'max_score', 'status']) : null;
            $state = $r ? ($r->status === 'published' ? 'marked' : 'submitted') : (($i->due_at && $i->due_at->isPast()) ? 'missed' : 'to_do');

            return $row + ['state' => $state, 'score' => $r && $state === 'marked' ? (float) $r->final_score : null, 'max' => $r ? (float) $r->max_score : $max, 'late' => false];
        })->filter()->values()->all();
    }

    public function updateOutline(Request $request, CourseOffering $offering, RichTextSanitizer $sanitizer): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $data = $request->validate(['outline_html' => ['nullable', 'string', 'max:100000']]);
        $offering->update(['outline_html' => $sanitizer->clean($data['outline_html'] ?? '')]);

        return back()->with('success', 'Course outline saved.');
    }

    /** Lecturer submits the course for academic review; HOD/Dean approves (LMS 42). */
    public function review(Request $request, CourseOffering $offering, AuditLogger $audit): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:submit,approve,return']])['action'];
        if ($action === 'submit') {
            $this->access->authorizeTeach($request->user(), $offering);
            $offering->update(['review_status' => 'submitted']);
        } else {
            abort_unless($this->access->oversees($request->user()) && ! $this->access->role($request->user(), $offering), 403, 'Reviewed by the HOD or Dean, not the course teacher.');
            $offering->update(['review_status' => $action === 'approve' ? 'approved' : 'draft']);
        }
        $audit->record("lms.course.review.$action", $offering);

        return back()->with('success', 'Review status updated.');
    }

    public function storeSection(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $data = $request->validate(['title' => ['required', 'string', 'max:190'], 'summary' => ['nullable', 'string', 'max:2000'], 'release_at' => ['nullable', 'date'], 'visible' => ['boolean']]);
        CourseSection::create($data + ['course_offering_id' => $offering->id, 'position' => (int) CourseSection::where('course_offering_id', $offering->id)->max('position') + 1]);

        return back()->with('success', 'Section added.');
    }

    /** Lay the course out week by week: Week 1 … Week N, each opening on its week (from the semester start). */
    public function setupWeeks(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $data = $request->validate(['weeks' => ['required', 'integer', 'min:1', 'max:30'], 'starts_on' => ['nullable', 'date'], 'release' => ['boolean']]);
        $start = \Illuminate\Support\Carbon::parse($data['starts_on'] ?? $offering->semester?->starts_on ?? now())->startOfWeek();
        $existing = CourseSection::where('course_offering_id', $offering->id)->pluck('title')->map(fn ($t) => mb_strtolower(trim($t)));
        $position = (int) CourseSection::where('course_offering_id', $offering->id)->max('position');
        $made = 0;
        DB::transaction(function () use ($data, $start, $existing, $offering, &$position, &$made) {
            for ($w = 1; $w <= $data['weeks']; $w++) {
                if ($existing->contains(fn ($t) => $t === "week $w" || str_starts_with($t, "week $w:") || str_starts_with($t, "week $w "))) {
                    continue; // already there
                }
                CourseSection::create(['course_offering_id' => $offering->id, 'title' => "Week $w", 'summary' => null, 'position' => ++$position,
                    'release_at' => ($data['release'] ?? true) ? $start->copy()->addWeeks($w - 1) : null, 'visible' => true]);
                $made++;
            }
        });

        return back()->with('success', $made ? "$made weekly sections added. Add materials, quizzes and assignments to each week; students see a week when it opens." : 'Those weeks are already there.');
    }

    public function updateSection(Request $request, CourseOffering $offering, CourseSection $section): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        abort_unless($section->course_offering_id === $offering->id, 404);
        $section->update($request->validate(['title' => ['sometimes', 'string', 'max:190'], 'summary' => ['nullable', 'string', 'max:2000'],
            'release_at' => ['nullable', 'date'], 'visible' => ['boolean'], 'position' => ['integer', 'min:0']]));

        return back();
    }

    public function storeItem(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $item = $this->content->saveItem($offering, $this->validateItem($request), $request->file('file'), $request->user());

        return redirect("/learn/items/{$item->id}")->with('success', 'Added.');
    }

    public function updateItem(Request $request, CourseItem $item): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $item->offering);
        $this->content->saveItem($item->offering, $this->validateItem($request, false), $request->file('file'), $request->user(), $item);

        return back()->with('success', 'Saved.');
    }

    public function destroyItem(Request $request, CourseItem $item, AuditLogger $audit): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $item->offering);
        $offering = $item->course_offering_id;
        $item->delete(); // soft delete: submissions and marks are kept
        $audit->record('lms.item.deleted', $item);

        return redirect("/learn/courses/$offering")->with('success', 'Removed.');
    }

    public function complete(Request $request, CourseItem $item): RedirectResponse
    {
        $this->access->authorizeOpen($request->user(), $item);
        $this->content->complete($item, $request->user());

        return back();
    }

    /** Private file download, only for people who may open the item (LMS 29, 206). */
    public function download(Request $request, CourseItem $item): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->access->authorizeOpen($request->user(), $item);
        abort_unless($item->file_path && Storage::disk('local')->exists($item->file_path), 404);
        // Shown in the page, a material counts as done when the student says so, reaches the end of
        // a video or the last page of a PDF; a file that can only be downloaded counts once downloaded.
        $range = (string) $request->header('Range');
        if ($request->boolean('download') && ($range === '' || str_starts_with($range, 'bytes=0-')) && ! $this->access->teaches($request->user(), $item->offering)) {
            $this->content->complete($item, $request->user());
            $this->content->viewed($item, $request->user(), true);
        }
        $inline = ! $request->boolean('download') && in_array($item->file_mime, self::INLINE, true);
        $response = response()->file(Storage::disk('local')->path($item->file_path), [
            'Content-Type' => $item->file_mime ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ]);
        $response->setContentDisposition($inline ? 'inline' : 'attachment', $item->file_name, \Illuminate\Support\Str::ascii($item->file_name) ?: 'file');

        return $response;
    }

    /** A picture or diagram put into a page or announcement; kept private to the course. */
    public function uploadMedia(Request $request, CourseOffering $offering): \Illuminate\Http\JsonResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $file = $request->validate(['file' => ['required', 'file', 'max:15360', 'extensions:png,jpg,jpeg,gif,webp', 'mimes:png,jpg,jpeg,gif,webp']],
            ['file.max' => 'Pictures can be up to 15 MB.', 'file.extensions' => 'Use a PNG, JPG, GIF or WebP picture.', 'file.mimes' => 'Use a PNG, JPG, GIF or WebP picture.'])['file'];
        $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $file->storeAs("lms/{$offering->tenant_id}/media/{$offering->id}", $name, 'local');

        return response()->json(['url' => "/learn/courses/{$offering->id}/media/$name"]);
    }

    public function media(Request $request, CourseOffering $offering, string $name): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->access->authorizeView($request->user(), $offering);
        abort_unless(preg_match('/^[a-f0-9-]{36}\.(png|jpe?g|gif|webp)$/', $name, $m), 404);
        $path = Storage::disk('local')->path("lms/{$offering->tenant_id}/media/{$offering->id}/$name");
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'image/'.($m[1] === 'jpg' ? 'jpeg' : $m[1]), 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** Files the browser shows itself (never HTML or SVG, which could run scripts). */
    private const INLINE = ['application/pdf', 'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/aac',
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'text/plain'];

    public function copy(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $from = CourseOffering::findOrFail($request->validate(['from' => ['required', 'integer']])['from']);
        $this->access->authorizeTeach($request->user(), $from);
        $n = $this->content->copyFrom($from, $offering, $request->user());

        return back()->with('success', "Copied $n items. Check the dates before students see them.");
    }

    private function validateItem(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'course_section_id' => [$creating ? 'required' : 'sometimes', 'integer'],
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(array_merge(['page', 'file', 'link', 'video', 'live', 'assignment', 'forum'],
                app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(tenant(), 'question_bank') ? ['quiz'] : []))],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:190'],
            'body_html' => ['nullable', 'string', 'max:500000'],
            'url' => ['nullable', 'url', 'max:2000'],
            'file' => ['nullable', 'file', 'max:512000'],
            'release_at' => ['nullable', 'date'], 'due_at' => ['nullable', 'date'], 'cutoff_at' => ['nullable', 'date', 'after_or_equal:due_at'],
            'visible' => ['boolean'], 'requires_completion' => ['boolean'],
            'prerequisite_item_id' => ['nullable', 'integer'],
            'settings' => ['nullable', 'array'],
            'settings.max_score' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'settings.max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'settings.allow_late' => ['nullable', 'boolean'],
            'settings.late_penalty_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.max_penalty_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.penalty_per' => ['nullable', 'in:day,flat'],
            'settings.group' => ['nullable', 'boolean'], 'settings.anonymous' => ['nullable', 'boolean'], 'settings.peer_review' => ['nullable', 'boolean'],
            'settings.text' => ['nullable', 'boolean'], 'settings.file' => ['nullable', 'boolean'],
            'settings.file_types' => ['nullable', 'array'], 'settings.file_types.*' => ['string', 'alpha_num', 'max:8'],
            'settings.kind' => ['nullable', 'in:objective,theory,mixed,practical'],
            'settings.max_mb' => ['nullable', 'integer', 'min:1', 'max:500'],
            'settings.rubric' => ['nullable', 'array', 'max:20'],
            'settings.rubric.*.criterion' => ['required', 'string', 'max:300'],
            'settings.rubric.*.levels' => ['required', 'array', 'min:1', 'max:8'],
            'settings.rubric.*.levels.*.label' => ['required', 'string', 'max:120'],
            'settings.rubric.*.levels.*.points' => ['required', 'numeric', 'min:0'],
            'settings.grade_category_id' => ['nullable', 'integer'],
            'settings.duration' => ['nullable', 'integer', 'min:1', 'max:600'],
            'settings.attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
    }
}
