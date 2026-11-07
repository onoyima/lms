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
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseController extends Controller
{
    public function __construct(private LmsAccess $access, private CourseContentService $content) {}

    /** Student: registered courses with progress and what is due. Lecturer: courses they teach (LMS 26, 43, 46). */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $teaching = CourseOffering::with('course', 'semester')
            ->whereIn('id', DB::table('course_offering_staff')->where('user_id', $user->id)->pluck('course_offering_id'))
            ->withCount(['enrolments as students' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('semester_id')->get()
            ->map(fn ($o) => ['id' => $o->id, 'code' => $o->course->code, 'title' => $o->course->title, 'semester' => $o->semesterLabel(), 'students' => $o->students,
                'role' => $this->access->role($user, $o), 'to_mark' => DB::table('assignment_submissions')->join('course_items', 'course_items.id', '=', 'assignment_submissions.course_item_id')
                    ->where('course_items.course_offering_id', $o->id)->where('assignment_submissions.status', 'submitted')->count()]);

        $enrolled = Enrolment::with('offering.course', 'offering.semester', 'offering.staff:id,name')->where('user_id', $user->id)->whereIn('status', ['active', 'completed'])
            ->orderByDesc('id')->get()->map(fn ($e) => ['id' => $e->offering->id, 'code' => $e->offering->course->code, 'title' => $e->offering->course->title,
                'lecturers' => $e->offering->staff->sortBy(fn ($s) => $s->pivot->role === 'coordinator' ? 0 : 1)->pluck('name')->values(),
                'semester' => $e->offering->semesterLabel(), 'status' => $e->status, 'category' => $e->category, 'progress' => $this->content->progress($e->offering, $user)]);

        $offeringIds = $enrolled->where('status', 'active')->pluck('id');
        $upcoming = CourseItem::with('offering.course')->whereIn('course_offering_id', $offeringIds)->whereIn('type', ['assignment', 'quiz'])
            ->where('visible', true)->whereNotNull('due_at')->where('due_at', '>=', now()->subDay())->orderBy('due_at')->limit(12)->get()
            ->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'type' => $i->type, 'course' => $i->offering->course->code, 'due_at' => $i->due_at,
                'submitted' => $i->type === 'assignment' && DB::table('assignment_submissions')->where('course_item_id', $i->id)->where('user_id', $user->id)->where('status', '!=', 'draft')->exists()]);

        return Inertia::render('learn/courses', ['teaching' => $teaching, 'enrolled' => $enrolled, 'upcoming' => $upcoming]);
    }

    public function show(Request $request, CourseOffering $offering): Response
    {
        $user = $request->user();
        $this->access->authorizeView($user, $offering);
        $teacher = $this->access->teaches($user, $offering);
        $completed = $teacher ? [] : $this->access->completedIds($user, $offering);
        $offering->load('course.department', 'semester', 'staff');

        $sections = CourseSection::where('course_offering_id', $offering->id)->orderBy('position')
            ->with(['items' => fn ($q) => $q->orderBy('position')->with('prerequisite:id,title')])->get()
            ->filter(fn ($s) => $teacher || ($s->visible && (! $s->release_at || $s->release_at->isPast())))
            ->map(fn ($s) => [
                'id' => $s->id, 'title' => $s->title, 'summary' => $s->summary, 'visible' => $s->visible, 'release_at' => $s->release_at,
                'items' => $s->items->map(function (CourseItem $i) use ($teacher, $completed, $s) {
                    $i->setRelation('section', $s);
                    [$open, $why] = $teacher ? [true, null] : $this->access->availability($i, $completed);

                    return $teacher || $why !== 'hidden' ? [
                        'id' => $i->id, 'type' => $i->type, 'title' => $i->title, 'visible' => $i->visible, 'release_at' => $i->release_at, 'due_at' => $i->due_at,
                        'open' => $open, 'locked_reason' => $why, 'done' => in_array($i->id, $completed, true), 'file_name' => $i->file_name,
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
            'schedule' => DB::table('class_schedules')->where('course_offering_id', $offering->id)->orderBy('weekday')->orderBy('starts_at')
                ->get(['weekday', 'starts_at', 'ends_at', 'kind', 'venue', 'group_label'])->map(fn ($r) => ['weekday' => (int) $r->weekday, 'starts_at' => substr($r->starts_at, 0, 5),
                    'ends_at' => substr($r->ends_at, 0, 5), 'kind' => $r->kind, 'venue' => $r->venue, 'group_label' => $r->group_label]),
        ]);
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
    public function download(Request $request, CourseItem $item): StreamedResponse
    {
        $this->access->authorizeOpen($request->user(), $item);
        abort_unless($item->file_path && Storage::disk('local')->exists($item->file_path), 404);
        if (! $this->access->teaches($request->user(), $item->offering)) {
            $this->content->complete($item, $request->user());
        }
        $inline = in_array($item->file_mime, ['application/pdf', 'video/mp4', 'video/webm', 'audio/mpeg', 'audio/mp4', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);

        return Storage::disk('local')->response($item->file_path, $item->file_name, [
            'Content-Type' => $item->file_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }

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
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(['page', 'file', 'link', 'video', 'live', 'assignment', 'quiz', 'forum'])],
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
