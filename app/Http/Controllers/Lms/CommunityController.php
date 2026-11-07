<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\ClassMeeting;
use App\Models\CourseOffering;
use App\Services\Lms\CourseContentService;
use App\Services\Lms\LmsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Announcements, notifications inbox and attendance (LMS 34, 36, 50, 180-188). */
class CommunityController extends Controller
{
    public function __construct(private LmsAccess $access, private CourseContentService $content) {}

    public function announcements(Request $request): Response
    {
        $user = $request->user();
        $offerings = DB::table('enrolments')->where('user_id', $user->id)->where('status', 'active')->pluck('course_offering_id')
            ->merge(DB::table('course_offering_staff')->where('user_id', $user->id)->pluck('course_offering_id'));
        $departments = DB::table('course_offerings')->join('courses', 'courses.id', '=', 'course_offerings.course_id')->whereIn('course_offerings.id', $offerings)->pluck('courses.department_id')->filter()->unique();
        $faculties = DB::table('departments')->whereIn('id', $departments)->pluck('faculty_id')->unique();

        $list = Announcement::with('author:id,name')->where('publish_at', '<=', now())
            ->where(fn ($q) => $q->where('scope', 'institution')
                ->orWhere(fn ($q) => $q->where('scope', 'offering')->whereIn('scope_id', $offerings))
                ->orWhere(fn ($q) => $q->where('scope', 'department')->whereIn('scope_id', $departments))
                ->orWhere(fn ($q) => $q->where('scope', 'faculty')->whereIn('scope_id', $faculties)))
            ->orderByDesc('publish_at')->paginate(20)
            ->through(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'body_html' => $a->body_html, 'urgent' => $a->urgent, 'scope' => $a->scope, 'author' => $a->author?->name, 'publish_at' => $a->publish_at]);

        return Inertia::render('learn/announcements', [
            'announcements' => $list,
            'targets' => $this->targets($request),
        ]);
    }

    /** Who this user may post to: their courses; HOD/Dean their department/faculty; admins everyone (LMS 180, 185). */
    private function targets(Request $request): array
    {
        $user = $request->user();
        $t = CourseOffering::with('course', 'semester')->whereIn('id', DB::table('course_offering_staff')->where('user_id', $user->id)->pluck('course_offering_id'))->get()
            ->map(fn ($o) => ['scope' => 'offering', 'id' => $o->id, 'label' => $o->label()])->all();
        if ($user->can('results.approve') || $user->can('academics.manage')) {
            foreach (DB::table('departments')->get(['id', 'name']) as $d) {
                $t[] = ['scope' => 'department', 'id' => $d->id, 'label' => "Department: {$d->name}"];
            }
            foreach (DB::table('faculties')->get(['id', 'name']) as $f) {
                $t[] = ['scope' => 'faculty', 'id' => $f->id, 'label' => "Faculty: {$f->name}"];
            }
        }
        if ($user->can('institution.manage') || $user->can('exams.manage')) {
            $t[] = ['scope' => 'institution', 'id' => null, 'label' => 'Everyone in the institution'];
        }

        return $t;
    }

    public function announce(Request $request): RedirectResponse
    {
        $data = $request->validate(['scope' => ['required', 'in:offering,department,faculty,institution'], 'scope_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:190'], 'body_html' => ['required', 'string', 'max:50000'], 'urgent' => ['boolean'], 'publish_at' => ['nullable', 'date']]);
        $allowed = collect($this->targets($request))->contains(fn ($t) => $t['scope'] === $data['scope'] && ($t['id'] ?? null) == ($data['scope_id'] ?? null));
        abort_unless($allowed, 403, 'You cannot post to that audience.');
        $a = $this->content->announce($data, $request->user());

        return back()->with('success', "Announcement sent to {$a->recipients} people.");
    }

    // ── Attendance ────────────────────────────────────────────────────────

    public function attendance(Request $request, CourseOffering $offering): Response
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $meetings = ClassMeeting::where('course_offering_id', $offering->id)->orderByDesc('held_at')->limit(60)->get();
        $students = DB::table('enrolments')->join('users', 'users.id', '=', 'enrolments.user_id')->where('course_offering_id', $offering->id)->where('enrolments.status', 'active')
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.identifier']);
        $records = DB::table('attendance_records')->whereIn('class_meeting_id', $meetings->pluck('id'))->get()->groupBy('user_id')->map(fn ($g) => $g->pluck('status', 'class_meeting_id'));

        return Inertia::render('learn/attendance', [
            'offering' => ['id' => $offering->id, 'label' => $offering->label()],
            'meetings' => $meetings->map(fn ($m) => ['id' => $m->id, 'held_at' => $m->held_at, 'topic' => $m->topic, 'code' => $m->code_expires_at?->isFuture() ? $m->code : null, 'code_expires_at' => $m->code_expires_at,
                'recording' => $m->recording_url || $m->recording_path ? ['url' => $m->recording_url, 'file' => (bool) $m->recording_path, 'size' => $m->recording_size, 'published' => (bool) $m->recording_published] : null]),
            'students' => $students->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'identifier' => $s->identifier, 'records' => $records[$s->id] ?? (object) [],
                'rate' => $meetings->count() ? round(collect($records[$s->id] ?? [])->filter(fn ($st) => in_array($st, ['present', 'late', 'excused'], true))->count() / $meetings->count() * 100) : null]),
        ]);
    }

    public function storeMeeting(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $data = $request->validate(['held_at' => ['required', 'date'], 'topic' => ['nullable', 'string', 'max:190'], 'code_minutes' => ['nullable', 'integer', 'min:1', 'max:180']]);
        ClassMeeting::create(['course_offering_id' => $offering->id, 'held_at' => $data['held_at'], 'topic' => $data['topic'] ?? null,
            'code' => ! empty($data['code_minutes']) ? strtoupper(Str::random(6)) : null, 'code_expires_at' => ! empty($data['code_minutes']) ? now()->addMinutes($data['code_minutes']) : null]);

        return back()->with('success', 'Class added.');
    }

    public function mark(Request $request, ClassMeeting $meeting): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), CourseOffering::findOrFail($meeting->course_offering_id));
        $data = $request->validate(['records' => ['required', 'array', 'max:2000'], 'records.*' => ['in:present,late,absent,excused']]);
        $enrolled = DB::table('enrolments')->where('course_offering_id', $meeting->course_offering_id)->whereIn('user_id', array_keys($data['records']))->pluck('user_id')->flip();
        $rows = collect($data['records'])->filter(fn ($s, $uid) => isset($enrolled[$uid]))->map(fn ($status, $uid) => ['class_meeting_id' => $meeting->id, 'user_id' => $uid,
            'status' => $status, 'source' => 'staff', 'created_at' => now(), 'updated_at' => now()])->values()->all();
        DB::table('attendance_records')->upsert($rows, ['class_meeting_id', 'user_id'], ['status', 'source', 'updated_at']);

        return back()->with('success', 'Attendance saved.');
    }

    /** Students mark themselves present with the code shown in class. */
    public function checkIn(Request $request): RedirectResponse
    {
        $code = strtoupper(trim($request->validate(['code' => ['required', 'string', 'max:8']])['code']));
        $meeting = ClassMeeting::where('code', $code)->where('code_expires_at', '>', now())->first();
        abort_unless($meeting && $this->access->learns($request->user(), CourseOffering::findOrFail($meeting->course_offering_id)), 422, 'That code is not valid for any of your classes.');
        DB::table('attendance_records')->upsert([['class_meeting_id' => $meeting->id, 'user_id' => $request->user()->id, 'status' => 'present', 'source' => 'code',
            'created_at' => now(), 'updated_at' => now()]], ['class_meeting_id', 'user_id'], ['status', 'updated_at']);

        return back()->with('success', 'You are marked present.');
    }

    /** Attach a lecture recording to a class: a link to the institution's video service, or an uploaded file (LMS 30). */
    public function saveRecording(Request $request, ClassMeeting $meeting): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), CourseOffering::findOrFail($meeting->course_offering_id));
        $data = $request->validate(['url' => ['nullable', 'url', 'starts_with:https://', 'max:500'], 'upload_id' => ['nullable', 'uuid'], 'published' => ['boolean'], 'remove' => ['boolean']]);
        if ($request->boolean('remove')) {
            if ($meeting->recording_path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($meeting->recording_path);
            }
            $meeting->forceFill(['recording_url' => null, 'recording_path' => null, 'recording_size' => null, 'recording_published' => false])->save();

            return back()->with('success', 'Recording removed.');
        }
        if (! empty($data['upload_id'])) {
            $file = app(\App\Services\Lms\Uploads::class)->take($data['upload_id'], $request->user(), 'recording:'.$meeting->id);
            $path = $file->storeAs('recordings/'.tenant_id(), Str::random(40).'.'.strtolower($file->getClientOriginalExtension()), 'local');
            if ($meeting->recording_path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($meeting->recording_path);
            }
            $meeting->forceFill(['recording_path' => $path, 'recording_size' => $file->getSize(), 'recording_url' => null]);
        } elseif (array_key_exists('url', $data) && $data['url']) {
            $meeting->forceFill(['recording_url' => $data['url']]);
        }
        $meeting->forceFill(['recording_published' => (bool) ($data['published'] ?? $meeting->recording_published)])->save();

        return back()->with('success', $meeting->recording_published ? 'Recording saved and visible to the class.' : 'Recording saved. Publish it when it is ready.');
    }

    /** Stream an uploaded recording to the class (supports seeking). */
    public function recording(Request $request, ClassMeeting $meeting)
    {
        $offering = CourseOffering::findOrFail($meeting->course_offering_id);
        $teaches = $this->access->teaches($request->user(), $offering);
        abort_unless($teaches || ($meeting->recording_published && $this->access->learns($request->user(), $offering)), 403);
        abort_unless($meeting->recording_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($meeting->recording_path), 404);
        $ext = pathinfo($meeting->recording_path, PATHINFO_EXTENSION);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($meeting->recording_path), [
            'Content-Type' => ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg'][$ext] ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline',
        ]);
    }
}
