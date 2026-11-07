<?php

namespace App\Http\Controllers\Conferencing;

use App\Conferencing\Attendance;
use App\Conferencing\BbbClient;
use App\Conferencing\BbbException;
use App\Conferencing\MeetingAccess;
use App\Conferencing\MeetingService;
use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingRecording;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Lms\LmsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Meetings and virtual classes: list, schedule, lobby, join, run, attendance and recordings. */
class MeetingController extends Controller
{
    public function __construct(private MeetingService $meetings, private MeetingAccess $access, private LmsAccess $lms, private AuditLogger $audit) {}

    private function card(Meeting $m, User $u): array
    {
        return ['uuid' => $m->uuid, 'title' => $m->title, 'type' => $m->type, 'type_label' => Meeting::TYPES[$m->type] ?? $m->type, 'status' => $m->status,
            'starts_at' => $m->starts_at, 'ends_at' => $m->endsAt(), 'timezone' => $m->timezone, 'course' => $m->offering?->label(),
            'recurring' => (bool) ($m->series_id || $m->recurrence), 'moderator' => $this->access->moderates($u, $m)];
    }

    /** Meetings this person can see: theirs, their courses', the institution's open ones. */
    private function visible(User $u)
    {
        $teach = DB::table('course_offering_staff')->where('user_id', $u->id)->pluck('course_offering_id');
        $learn = DB::table('enrolments')->where('user_id', $u->id)->where('status', 'active')->pluck('course_offering_id');

        return Meeting::with('offering.course', 'participants')->where(fn ($q) => $q->when($this->access->canManageAll($u), fn ($w) => $w->whereRaw('1=1'), fn ($w) => $w
            ->where('created_by', $u->id)->orWhereIn('id', MeetingParticipant::where('user_id', $u->id)->select('meeting_id'))
            ->orWhereIn('course_offering_id', $teach->merge($learn))->orWhere('access', 'institution')));
    }

    public function index(Request $request): Response
    {
        $u = $request->user();
        $course = $request->integer('course') ?: null;
        $base = fn () => $this->visible($u)->when($course, fn ($q) => $q->where('course_offering_id', $course));

        return Inertia::render('meetings/index', [
            'live' => $base()->where('status', 'live')->orderBy('starts_at')->get()->map(fn ($m) => $this->card($m, $u)),
            'upcoming' => $base()->where('status', 'scheduled')->where('starts_at', '>', now()->subMinutes(60))->orderBy('starts_at')->limit(100)->get()->map(fn ($m) => $this->card($m, $u)),
            'past' => $base()->whereIn('status', ['ended', 'cancelled'])->orderByDesc('starts_at')->limit(50)->get()->map(fn ($m) => $this->card($m, $u)),
            'course' => $course ? CourseOffering::find($course)?->label() : null,
            'courseId' => $course,
            'canCreate' => $this->access->canCreate($u),
        ]);
    }

    /** What the form needs besides courses: BigBlueButton layouts and features, and vivas or defences that can be held in the room. */
    private function formExtras(User $u): array
    {
        $exams = \App\Support\Exams\ExamAccess::scope(\App\Models\Exam::query(), $u)->whereIn('type', ['viva', 'practical', 'research'])
            ->latest('id')->limit(200)->get(['id', 'title', 'type'])->map(fn ($e) => ['id' => $e->id, 'label' => $e->title.' ('.$e->type.')'])->all();

        return ['layouts' => Meeting::LAYOUTS, 'features' => Meeting::FEATURES, 'exams' => $exams,
            'builtinMax' => app(\App\Conferencing\BuiltinRoom::class)->max(),
            'hasServer' => \App\Models\ConferenceServer::usableBy(tenant_id())->exists()];
    }

    private function courseOptions(User $u): array
    {
        $ids = DB::table('course_offering_staff')->where('user_id', $u->id)->pluck('course_offering_id');

        return CourseOffering::with('course')->when(! $this->access->canManageAll($u) && ! $this->lms->oversees($u), fn ($q) => $q->whereIn('id', $ids))
            ->latest('id')->limit(300)->get()->map(fn ($o) => ['id' => $o->id, 'label' => $o->label()])->all();
    }

    public function create(Request $request): Response
    {
        abort_unless($this->access->canCreate($request->user()), 403);

        return Inertia::render('meetings/form', ['meeting' => null, 'courses' => $this->courseOptions($request->user()), 'types' => Meeting::TYPES,
            'defaults' => Meeting::DEFAULT_SETTINGS, 'courseId' => $request->integer('course') ?: null, 'canHostAny' => $request->user()->can('meetings.host') || $this->access->canManageAll($request->user())] + $this->formExtras($request->user()));
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:190'], 'description' => ['nullable', 'string', 'max:2000'], 'type' => ['required', Rule::in(array_keys(Meeting::TYPES))],
            'course_offering_id' => ['nullable', 'integer'], 'starts_at' => ['required', 'date'], 'duration_minutes' => ['required', 'integer', 'min:10', 'max:600'],
            'timezone' => ['required', 'timezone'], 'access' => ['required', Rule::in(['course', 'invited', 'institution'])],
            'recurrence' => ['nullable', 'array'], 'recurrence.days' => ['array'], 'recurrence.days.*' => ['integer', 'between:1,7'], 'recurrence.until' => ['nullable', 'date', 'after:starts_at', 'before:+1 year'],
            'settings' => ['array'], 'settings.record' => ['boolean'], 'settings.auto_record' => ['boolean'], 'settings.waiting_room' => ['boolean'], 'settings.mute_on_start' => ['boolean'],
            'settings.max_participants' => ['nullable', 'integer', 'min:2', 'max:5000'], 'settings.lock_cameras' => ['boolean'], 'settings.lock_microphones' => ['boolean'],
            'settings.lock_public_chat' => ['boolean'], 'settings.lock_private_chat' => ['boolean'], 'settings.hide_user_list' => ['boolean'], 'settings.webcams_only_for_moderator' => ['boolean'],
            'settings.join_before_host' => ['boolean'], 'settings.early_join_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'settings.recording_visibility' => ['nullable', Rule::in(['hosts', 'attendees', 'institution'])], 'settings.welcome' => ['nullable', 'string', 'max:500'],
            'settings.layout' => ['nullable', Rule::in(Meeting::LAYOUTS)], 'settings.disabled_features' => ['nullable', 'array'], 'settings.disabled_features.*' => [Rule::in(array_keys(Meeting::FEATURES))],
            'settings.engine' => ['nullable', Rule::in(Meeting::ENGINES)],
            'settings.listen_only' => ['boolean'], 'settings.skip_audio_check' => ['boolean'],
            'settings.exam_id' => ['nullable', 'integer', Rule::exists('exams', 'id')->where('tenant_id', tenant_id())->whereIn('type', ['viva', 'practical', 'research'])],
        ];
    }

    private function checkCourse(Request $request, array &$data): void
    {
        $u = $request->user();
        unset($data['settings']['slides']); // slides are added through their own upload, never from the form
        if (! empty($data['settings']['exam_id'])) {
            $exam = \App\Models\Exam::findOrFail($data['settings']['exam_id']);
            abort_unless($u->can('exams.manage') || $exam->examiners()->where('users.id', $u->id)->exists(), 403, 'Only the exams office or this exam\'s examiners can hold it in a meeting.');
        }
        if (! empty($data['course_offering_id'])) {
            $o = CourseOffering::findOrFail($data['course_offering_id']); // another institution's course is not found
            abort_unless($this->lms->teaches($u, $o) || $this->access->canManageAll($u), 403, 'You do not teach this course.');
        } else {
            abort_unless($u->can('meetings.host') || $this->access->canManageAll($u), 403, 'Choose one of your courses, or ask for permission to host other meetings.');
            abort_if($data['access'] === 'course', 422, 'A meeting for everyone in a course needs a course.');
        }
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->access->canCreate($request->user()), 403);
        $data = $request->validate($this->rules());
        $this->checkCourse($request, $data);
        $m = $this->meetings->create($data, $request->user());

        return redirect()->route('meetings.show', $m)->with('success', $m->recurrence ? 'Meetings scheduled.' : 'Meeting scheduled.');
    }

    public function edit(Request $request, Meeting $meeting): Response
    {
        abort_unless($this->access->edits($request->user(), $meeting), 403);

        return Inertia::render('meetings/form', ['meeting' => $meeting->only(['uuid', 'title', 'description', 'type', 'course_offering_id', 'duration_minutes', 'timezone', 'access', 'settings'])
            + ['starts_at' => $meeting->starts_at->setTimezone($meeting->timezone)->format('Y-m-d\TH:i')], 'courses' => $this->courseOptions($request->user()), 'types' => Meeting::TYPES,
            'defaults' => Meeting::DEFAULT_SETTINGS, 'courseId' => $meeting->course_offering_id, 'canHostAny' => $request->user()->can('meetings.host') || $this->access->canManageAll($request->user())] + $this->formExtras($request->user()));
    }

    public function update(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_unless($this->access->edits($request->user(), $meeting), 403);
        abort_if(in_array($meeting->status, ['ended', 'cancelled'], true), 409, 'This meeting is over.');
        $data = $request->validate(array_diff_key($this->rules(), ['recurrence' => 1, 'recurrence.days' => 1, 'recurrence.days.*' => 1, 'recurrence.until' => 1]));
        $this->checkCourse($request, $data);
        $data['starts_at'] = \Carbon\CarbonImmutable::parse($data['starts_at'], $data['timezone'])->utc();
        $data['settings'] = array_intersect_key(($data['settings'] ?? []) + ($meeting->settings ?? []) + Meeting::DEFAULT_SETTINGS, Meeting::DEFAULT_SETTINGS);
        $meeting->update($data);
        $this->audit->record('meeting.updated', $meeting, ['title' => $meeting->title]);

        return redirect()->route('meetings.show', $meeting)->with('success', 'Meeting saved. Changes to room rules apply the next time the room is opened.');
    }

    public function show(Request $request, Meeting $meeting): Response
    {
        $u = $request->user();
        abort_unless($this->access->joins($u, $meeting), 403, 'You are not invited to this meeting.');
        $mod = $this->access->moderates($u, $meeting);
        [$open, $why] = $this->meetings->window($meeting, $mod);
        $recordings = MeetingRecording::where('meeting_id', $meeting->id)->where('state', '!=', 'deleted')
            ->when(! $mod, fn ($q) => $q->where('state', 'published')->whereIn('visibility', ['attendees', 'institution']))->latest('id')->get();

        return Inertia::render('meetings/show', [
            'meeting' => $this->card($meeting, $u) + ['description' => $meeting->description, 'access' => $meeting->access, 'settings' => $meeting->settings,
                'host' => $meeting->creator?->name, 'started_at' => $meeting->started_at, 'ended_at' => $meeting->ended_at, 'course_id' => $meeting->course_offering_id],
            'can' => ['moderate' => $mod, 'edit' => $this->access->edits($u, $meeting), 'join' => $open && ($mod || $meeting->status === 'live' || $meeting->setting('join_before_host'))],
            'notice' => $open ? (! $mod && $meeting->status !== 'live' && ! $meeting->setting('join_before_host') ? 'Waiting for the host to start the meeting.' : null) : $why,
            'participants' => $mod ? $meeting->participants()->with('user:id,name,identifier,email')->get()->map(fn ($p) => ['user_id' => $p->user_id, 'name' => $p->user?->name, 'identifier' => $p->user?->identifier, 'role' => $p->role]) : [],
            'attendance' => $mod ? app(Attendance::class)->summary($meeting) : array_values(array_filter(app(Attendance::class)->summary($meeting), fn ($r) => $r['user_id'] === $u->id)),
            'recordings' => $recordings->values()->map(fn ($r) => $r->only(['id', 'state', 'format', 'duration_seconds', 'started_at', 'visibility'])),
            'roles' => Meeting::ROLES,
            'slides' => $mod ? collect((array) $meeting->setting('slides'))->values()->map(fn ($s, $i) => ['index' => $i, 'name' => $s['name'] ?? 'slides', 'size' => $s['size'] ?? null]) : [],
            'exam' => $this->linkedExam($meeting, $u, $mod),
            'transcripts' => \App\Models\MeetingTranscript::whereIn('meeting_recording_id', $recordings->pluck('id'))->orderBy('id')
                ->get(['id', 'meeting_recording_id', 'language', 'kind', 'status', 'error'])->groupBy('meeting_recording_id'),
            'room' => ['engine' => app(\App\Conferencing\BuiltinRoom::class)->engine($meeting), 'max' => app(\App\Conferencing\BuiltinRoom::class)->max()],
            'guestLink' => $mod ? (fn ($l) => $l ? ['enabled' => $l->enabled, 'url' => $l->url(), 'passcode' => (bool) $l->passcode_hash] : null)(\App\Models\MeetingGuestLink::where('meeting_id', $meeting->id)->first()) : null,
            'transcription' => ['enabled' => app(\App\Conferencing\Transcription::class)->enabled(), 'translation' => app(\App\Conferencing\Transcription::class)->translationEnabled(),
                'languages' => config('conferencing.translation.languages')],
        ]);
    }

    /** The viva / defence held in this room: examiners see its candidates with a link to their score sheet. */
    private function linkedExam(Meeting $meeting, $u, bool $mod): ?array
    {
        $exam = $this->access->linkedExam($meeting);
        if (! $exam) {
            return null;
        }
        $examiner = $u->can('exams.manage') || $exam->examiners()->where('users.id', $u->id)->exists();

        return ['id' => $exam->id, 'title' => $exam->title, 'type' => $exam->type, 'examiner' => $examiner,
            'candidates' => $examiner ? \App\Models\ExamCandidate::with('user:id,name,identifier')->whereIn('exam_session_id', $exam->sessions()->pluck('id'))->limit(300)->get()
                ->unique('user_id')->map(fn ($c) => ['user_id' => $c->user_id, 'name' => $c->user?->name, 'identifier' => $c->user?->identifier,
                    'scored' => \App\Models\CriterionMark::where('exam_id', $exam->id)->where('user_id', $c->user_id)->where('examiner_id', $u->id)->exists()])->values() : []];
    }

    // ── slides preloaded in the room ──────────────────────────────────────────

    public function uploadSlide(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_unless($this->access->moderates($request->user(), $meeting), 403);
        $file = $request->validate(['file' => ['required', 'file', 'max:51200', 'extensions:pdf,pptx,ppt,docx,doc,odp,odt,png,jpg,jpeg']])['file'];
        $slides = array_values((array) $meeting->setting('slides'));
        abort_if(count($slides) >= 5, 422, 'Up to five files can be preloaded in one meeting.');
        $path = $file->store('meeting-slides/'.$meeting->tenant_id.'/'.$meeting->uuid, 'local');
        $slides[] = ['path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 120), 'size' => $file->getSize()];
        $meeting->update(['settings' => array_replace((array) $meeting->settings, ['slides' => $slides])]);
        $this->audit->record('meeting.slides_added', $meeting, ['name' => $file->getClientOriginalName()]);

        return back()->with('success', 'Slides added. They load in the room the next time it opens.');
    }

    public function deleteSlide(Request $request, Meeting $meeting, int $index): RedirectResponse
    {
        abort_unless($this->access->moderates($request->user(), $meeting), 403);
        $slides = array_values((array) $meeting->setting('slides'));
        abort_unless(isset($slides[$index]), 404);
        \Illuminate\Support\Facades\Storage::disk('local')->delete($slides[$index]['path']);
        array_splice($slides, $index, 1);
        $meeting->update(['settings' => array_replace((array) $meeting->settings, ['slides' => $slides])]);

        return back();
    }

    /** BigBlueButton fetches the slides through this short-lived signed link. */
    public function slide(string $meeting, int $index)
    {
        $meeting = Meeting::withoutGlobalScopes()->where('uuid', $meeting)->firstOrFail();
        $s = array_values((array) $meeting->setting('slides'))[$index] ?? null;
        abort_unless($s && str_starts_with($s['path'], 'meeting-slides/'.$meeting->tenant_id.'/'.$meeting->uuid.'/') && \Illuminate\Support\Facades\Storage::disk('local')->exists($s['path']), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($s['path'], $s['name'] ?? 'slides');
    }

    /** Polled by the lobby while waiting for the host. */
    public function status(Request $request, Meeting $meeting): JsonResponse
    {
        abort_unless($this->access->joins($request->user(), $meeting), 403);

        return response()->json(['status' => $meeting->fresh()->status]);
    }

    public function join(Request $request, Meeting $meeting): HttpResponse
    {
        abort_unless($this->access->joins($request->user(), $meeting), 403, 'You are not invited to this meeting.');
        if (app(\App\Conferencing\BuiltinRoom::class)->engine($meeting) === 'builtin') {
            return Inertia::location(route('meetings.room', $meeting)); // the built-in room (no meeting server needed)
        }
        try {
            $url = $this->meetings->joinUrl($meeting->load('participants'), $request->user());
        } catch (BbbException $e) {
            return back()->with('error', $e->getMessage());
        }

        return Inertia::location($url);
    }

    public function end(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_unless($this->access->moderates($request->user(), $meeting), 403);
        try {
            $this->meetings->end($meeting, $request->user());
        } catch (BbbException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Meeting ended for everyone.');
    }

    public function cancel(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_unless($this->access->edits($request->user(), $meeting), 403);
        $n = $this->meetings->cancel($meeting, $request->user(), $request->boolean('series'));

        return redirect()->route('meetings.index')->with('success', $n === 1 ? 'Meeting cancelled.' : "$n meetings cancelled.");
    }

    public function addParticipants(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_unless($this->access->edits($request->user(), $meeting), 403);
        $data = $request->validate(['people' => ['nullable', 'string', 'max:20000'], 'role' => ['required', Rule::in(Meeting::ROLES)], 'from_course' => ['boolean']]);
        $added = 0;
        $missing = [];
        $add = function (int $userId) use ($meeting, $data, $request, &$added) {
            $p = MeetingParticipant::firstOrNew(['meeting_id' => $meeting->id, 'user_id' => $userId]);
            if (! $p->exists) {
                $p->fill(['role' => $data['role'], 'added_by' => $request->user()->id])->save();
                $added++;
            } elseif ($p->role !== 'host' && ! $request->boolean('from_course')) {
                $p->update(['role' => $data['role']]); // named again: takes the chosen role (the host keeps theirs)
            }
        };
        if ($request->boolean('from_course') && $meeting->course_offering_id) {
            DB::table('enrolments')->where('course_offering_id', $meeting->course_offering_id)->where('status', 'active')->pluck('user_id')->each(fn ($id) => $add((int) $id));
        }
        foreach (preg_split('/[\s,;]+/', (string) ($data['people'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $who) {
            $user = User::where('identifier', $who)->orWhere('email', mb_strtolower($who))->first(); // this institution only
            $user ? $add($user->id) : $missing[] = $who;
        }
        $this->audit->record('meeting.participants_added', $meeting, ['count' => $added, 'role' => $data['role']]);

        return back()->with($missing ? 'error' : 'success', "$added added.".($missing ? ' Not found: '.implode(', ', array_slice($missing, 0, 10)).(count($missing) > 10 ? '…' : '') : ''));
    }

    public function setRole(Request $request, Meeting $meeting, int $user): RedirectResponse
    {
        abort_unless($this->access->edits($request->user(), $meeting), 403);
        $data = $request->validate(['role' => ['nullable', Rule::in(Meeting::ROLES)]]);
        $role = $data['role'] ?? null;
        abort_if($user === $meeting->created_by && $role !== 'host', 422, 'The meeting\'s creator stays its host.');
        $p = MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user)->firstOrFail();
        $role ? $p->update(['role' => $role]) : $p->delete();
        $this->audit->record('meeting.participant_role', $meeting, ['user' => $user, 'role' => $role]);

        return back()->with('success', $role ? 'Role changed.' : 'Removed from the meeting.');
    }

    /** Attendance of one meeting as Excel, CSV or PDF (?format=xlsx|csv|pdf; CSV by default for the old link). */
    public function attendanceCsv(Request $request, Meeting $meeting)
    {
        abort_unless($this->access->moderates($request->user(), $meeting), 403);
        $rows = app(Attendance::class)->summary($meeting);
        $format = in_array($request->query('format'), ['xlsx', 'csv', 'pdf'], true) ? $request->query('format') : 'csv';
        $this->audit->record('meeting.attendance_exported', $meeting, ['rows' => count($rows), 'format' => $format]);
        $columns = ['Name', 'ID number', 'Role', 'First joined', 'Last left', 'Minutes present', 'Percent of scheduled time', 'Times joined', 'Status'];
        $data = array_map(fn ($r) => [$r['name'], $r['identifier'], $r['role'], $r['first_join']?->format('Y-m-d H:i'), $r['last_leave']?->format('Y-m-d H:i'),
            round($r['seconds'] / 60, 1), $r['percent'], $r['sessions'], $r['status']], $rows);
        $exporter = app(\App\Services\Reports\Exporter::class);
        $name = 'attendance-'.\Illuminate\Support\Str::slug($meeting->title).'-'.$meeting->starts_at->format('Ymd');

        return match ($format) {
            'xlsx' => $exporter->xlsx($name, 'Attendance', $columns, $data),
            'pdf' => $exporter->pdf($name, 'pdf.report', ['title' => 'Attendance — '.$meeting->title, 'columns' => $columns, 'rows' => $data, 'truncated' => false,
                'subtitle' => $meeting->starts_at->format('l j F Y, H:i').' · '.$meeting->duration_minutes.' minutes · '.count($data).' people', 'footer' => $request->user()->name], 'landscape'),
            default => $exporter->csv($name, $columns, $data),
        };
    }

    /** Ask for a transcript of a recording (speech to text runs in the background). */
    public function transcribe(Request $request, Meeting $meeting, int $recording, \App\Conferencing\Transcription $service): RedirectResponse
    {
        $rec = $this->recording($request, $meeting, $recording, true);
        abort_unless($service->enabled(), 422, 'Transcription is not set up on this platform.');
        abort_unless($rec->state !== 'deleted' && $rec->playback_url, 422, 'This recording is not ready yet.');
        $busy = \App\Models\MeetingTranscript::where('meeting_recording_id', $rec->id)->where('kind', 'transcript')->whereIn('status', ['queued', 'working'])->exists();
        if (! $busy) {
            $t = \App\Models\MeetingTranscript::create(['meeting_recording_id' => $rec->id, 'kind' => 'transcript', 'status' => 'queued',
                'language' => config('conferencing.transcription.language') ?: null, 'requested_by' => $request->user()->id]);
            \App\Jobs\ProcessTranscript::dispatch($t->id, $meeting->tenant_id);
            $this->audit->record('meeting.transcript_requested', $meeting, ['recording' => $rec->record_id]);
        }

        return back()->with('success', 'The transcript is being made. It appears here when it is ready.');
    }

    public function translate(Request $request, Meeting $meeting, int $transcript, \App\Conferencing\Transcription $service): RedirectResponse
    {
        abort_unless($this->access->moderates($request->user(), $meeting), 403);
        abort_unless($service->translationEnabled(), 422, 'Translation is not set up on this platform.');
        $source = $this->transcriptOf($meeting, $transcript);
        abort_unless($source->kind === 'transcript' && $source->status === 'complete', 422, 'Only a finished transcript can be translated.');
        $lang = $request->validate(['language' => ['required', Rule::in(array_keys((array) config('conferencing.translation.languages')))]])['language'];
        $t = \App\Models\MeetingTranscript::updateOrCreate(['meeting_recording_id' => $source->meeting_recording_id, 'source_id' => $source->id, 'kind' => 'translation', 'language' => $lang],
            ['status' => 'queued', 'error' => null, 'requested_by' => $request->user()->id]);
        \App\Jobs\ProcessTranscript::dispatch($t->id, $meeting->tenant_id);

        return back()->with('success', 'The translation is being made.');
    }

    /** Captions (.vtt, .srt) or plain text, for anyone who may watch the recording. */
    public function transcriptFile(Request $request, Meeting $meeting, int $transcript, string $format): HttpResponse
    {
        $t = $this->transcriptOf($meeting, $transcript);
        $this->recording($request, $meeting, $t->meeting_recording_id, false);
        abort_unless($t->status === 'complete', 404);
        $body = match ($format) { 'vtt' => $t->toVtt(), 'srt' => $t->toSrt(), default => (string) $t->text };
        $name = \Illuminate\Support\Str::slug($meeting->title).'-'.($t->language ?: 'transcript').'.'.$format;

        return response($body, 200, ['Content-Type' => ($format === 'vtt' ? 'text/vtt' : 'text/plain').'; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    private function transcriptOf(Meeting $meeting, int $id): \App\Models\MeetingTranscript
    {
        return \App\Models\MeetingTranscript::whereIn('meeting_recording_id', MeetingRecording::where('meeting_id', $meeting->id)->select('id'))->findOrFail($id);
    }

    private function recording(Request $request, Meeting $meeting, int $id, bool $manage): MeetingRecording
    {
        $rec = MeetingRecording::where('meeting_id', $meeting->id)->findOrFail($id);
        $mod = $this->access->moderates($request->user(), $meeting);
        if ($manage) {
            abort_unless($mod, 403);
        } else {
            $attended = DB::table('meeting_attendance')->where('meeting_id', $meeting->id)->where('user_id', $request->user()->id)->exists();
            abort_unless($mod || ($rec->state === 'published' && ($rec->visibility === 'institution' || ($rec->visibility === 'attendees' && ($attended || $this->access->joins($request->user(), $meeting))))), 403);
        }

        return $rec;
    }

    /** Playback: a signed link that lasts minutes; the BigBlueButton address is never put in a page. */
    public function play(Request $request, Meeting $meeting, int $recording): RedirectResponse
    {
        $rec = $this->recording($request, $meeting, $recording, false);
        abort_unless($rec->playback_url, 404, 'This recording is still being processed.');
        $this->audit->record('meeting.recording_viewed', $meeting, ['recording' => $rec->record_id]);

        return redirect()->away(URL::temporarySignedRoute('conferencing.playback', now()->addMinutes((int) config('conferencing.playback_link_minutes')), ['recording' => $rec->id, 'u' => $request->user()->id]));
    }

    public function playback(Request $request, MeetingRecording $recording): RedirectResponse
    {
        abort_unless($request->user()?->id === $request->integer('u'), 403, 'This playback link belongs to someone else.');

        return redirect()->away((string) $recording->playback_url);
    }

    public function updateRecording(Request $request, Meeting $meeting, int $recording): RedirectResponse
    {
        $rec = $this->recording($request, $meeting, $recording, true);
        $data = $request->validate(['action' => ['required', Rule::in(['publish', 'unpublish', 'delete', 'visibility'])], 'visibility' => ['nullable', Rule::in(['hosts', 'attendees', 'institution'])]]);
        try {
            $bbb = $meeting->server ? BbbClient::for($meeting->server) : null;
            match ($data['action']) {
                'publish' => [$bbb?->publish($rec->record_id, true), $rec->update(['state' => 'published'])],
                'unpublish' => [$bbb?->publish($rec->record_id, false), $rec->update(['state' => 'unpublished'])],
                'delete' => [$bbb?->deleteRecording($rec->record_id), $rec->update(['state' => 'deleted', 'playback_url' => null])],
                'visibility' => $rec->update(['visibility' => $data['visibility'] ?? 'attendees']),
            };
        } catch (BbbException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->audit->record('meeting.recording_'.$data['action'], $meeting, ['recording' => $rec->record_id]);

        return back()->with('success', 'Recording updated.');
    }

    public function syncRecordings(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_unless($this->access->moderates($request->user(), $meeting), 403);
        try {
            $n = $this->meetings->syncRecordings($meeting);
        } catch (BbbException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $n ? "$n recording(s) found." : 'No recordings yet. Processing can take a while after the meeting ends.');
    }
}
