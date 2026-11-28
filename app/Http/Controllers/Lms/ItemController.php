<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\CourseGroup;
use App\Models\CourseItem;
use App\Models\CourseItemRevision;
use App\Models\DeadlineExtension;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\PeerReview;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Lms\AssignmentService;
use App\Services\Lms\CourseContentService;
use App\Services\Lms\LmsAccess;
use App\Services\Questions\RichTextSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One page per course item: material, assignment, quiz or forum (LMS 29-35, 53, 56-69). */
class ItemController extends Controller
{
    public function __construct(private LmsAccess $access, private AssignmentService $assignments, private CourseContentService $content) {}

    public function show(Request $request, CourseItem $item): Response
    {
        $user = $request->user();
        $this->access->authorizeOpen($user, $item);
        abort_if($item->type === 'quiz' && ! app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(tenant(), 'question_bank'), 404, 'Course quizzes are not available: the exams module is switched off for this institution.');
        $offering = $item->offering()->with('course')->first();
        $teacher = $this->access->teaches($user, $offering);
        if (! $teacher && in_array($item->type, ['page', 'link', 'video', 'live'], true)) {
            $this->content->complete($item, $user);
        }

        $data = [
            'item' => [
                'id' => $item->id, 'type' => $item->type, 'title' => $item->title, 'body_html' => $item->body_html, 'url' => $item->url,
                'file_name' => $item->file_name, 'file_mime' => $item->file_mime, 'file_size' => $item->file_size,
                'release_at' => $item->release_at, 'due_at' => $item->due_at, 'cutoff_at' => $item->cutoff_at, 'visible' => $item->visible,
                'requires_completion' => $item->requires_completion, 'prerequisite_item_id' => $item->prerequisite_item_id, 'course_section_id' => $item->course_section_id,
                'settings' => $item->type === 'assignment' ? AssignmentService::settings($item) : ($item->settings ?? []), 'version' => $item->version, 'exam_id' => $item->exam_id,
            ],
            'offering' => ['id' => $offering->id, 'code' => $offering->course->code, 'title' => $offering->course->title],
            'teacher' => $teacher,
            'canGrade' => $this->access->canGrade($user, $offering),
            'sections' => $teacher ? DB::table('course_sections')->where('course_offering_id', $offering->id)->orderBy('position')->get(['id', 'title']) : [],
            'siblings' => $teacher ? CourseItem::where('course_offering_id', $offering->id)->where('id', '!=', $item->id)->orderBy('title')->get(['id', 'title']) : [],
            'revisions' => $teacher ? CourseItemRevision::where('course_item_id', $item->id)->orderByDesc('version')->limit(20)->get(['version', 'edited_by', 'created_at']) : [],
            'preview' => \App\Services\Lms\MaterialPreview::kind($item->file_name),
            'nav' => $this->neighbours($item, $user, $teacher),
            'done' => ! $teacher && DB::table('item_completions')->where('course_item_id', $item->id)->where('user_id', $user->id)->exists(),
            'note' => $teacher ? null : \App\Models\CourseItemNote::where('course_item_id', $item->id)->where('user_id', $user->id)->first(['body', 'bookmarked']),
            'section' => DB::table('course_sections')->where('id', $item->course_section_id)->value('title'),
        ];

        if ($item->type === 'assignment') {
            $data += $teacher ? $this->assignmentForTeacher($item, $user) : $this->assignmentForStudent($item, $user);
        } elseif ($item->type === 'forum') {
            $data['threads'] = ForumThread::with('user:id,name')->where('course_item_id', $item->id)->orderByDesc('pinned')->orderByDesc('last_post_at')->paginate(25)
                ->through(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'author' => $t->user?->name, 'posts' => $t->posts_count, 'last_post_at' => $t->last_post_at, 'pinned' => $t->pinned, 'locked' => $t->locked]);
        } elseif ($item->type === 'quiz' && $item->exam_id) {
            $exam = $item->exam;
            if ($exam?->type === 'practical') {
                // a practical test: when and where for students; set-up and the score sheet for lecturers
                $result = $exam->results()->where('user_id', $user->id)->where('status', 'published')->first(['final_score', 'max_score']);
                $data['practical'] = ['exam_id' => $exam->id, 'status' => $exam->status, 'starts_at' => $item->release_at, 'ends_at' => $item->due_at,
                    'my_result' => $result, 'stations' => count((array) data_get($exam->settings, 'practical.stations', [])),
                    'setup' => $teacher ? ['criteria' => $exam->criteria()->get(), 'practical' => ['settings' => (array) data_get($exam->settings, 'practical', []), 'templates' => \App\Support\Exams\PracticalTemplates::all()]] : null];
            }
            $data['quiz'] = ['status' => $exam?->status, 'duration' => $exam?->duration_minutes, 'attempts' => $exam?->max_attempts, 'questions' => $exam ? DB::table('exam_section_questions')
                ->join('exam_sections', 'exam_sections.id', '=', 'exam_section_questions.exam_section_id')->where('exam_sections.exam_id', $exam->id)->count() : 0,
                'my_result' => $teacher ? null : DB::table('results')->where('exam_id', $item->exam_id)->where('user_id', $user->id)->first(['final_score', 'max_score', 'status'])];
        }

        return Inertia::render('learn/item', $data);
    }

    /**
     * The items before and after this one in the course order (sections, then items), skipping
     * those the student cannot open yet, so materials can be read one after another.
     */
    private function neighbours(CourseItem $item, User $user, bool $teacher): array
    {
        $offering = $item->offering;
        $completed = $teacher ? [] : $this->access->completedIds($user, $offering);
        $list = CourseItem::query()->join('course_sections', 'course_sections.id', '=', 'course_items.course_section_id')
            ->where('course_items.course_offering_id', $offering->id)->whereNull('course_items.deleted_at')
            ->orderBy('course_sections.position')->orderBy('course_items.position')->orderBy('course_items.id')
            ->select('course_items.*')->with(['section', 'prerequisite:id,title'])->get()
            ->filter(fn ($i) => $i->id === $item->id || $teacher || $this->access->availability($i, $completed)[0])->values();
        $at = $list->search(fn ($i) => $i->id === $item->id);
        $pick = fn ($i) => $i ? ['id' => $i->id, 'title' => $i->title, 'type' => $i->type] : null;

        return ['prev' => $at > 0 ? $pick($list[$at - 1]) : null, 'next' => $at !== false ? $pick($list[$at + 1] ?? null) : null,
            'position' => $at === false ? null : $at + 1, 'total' => $list->count()];
    }

    /** A Word, PowerPoint, Excel or text material as HTML, shown in the page (any phone, nothing to install). */
    public function preview(Request $request, CourseItem $item, \App\Services\Lms\MaterialPreview $preview): \Illuminate\Http\JsonResponse
    {
        $this->access->authorizeOpen($request->user(), $item);

        return response()->json($preview->render($item))->header('Cache-Control', 'private, max-age=600');
    }

    /** A picture from a material's preview (a figure in a Word file, a picture on a slide). */
    public function previewMedia(Request $request, CourseItem $item, string $name): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->access->authorizeOpen($request->user(), $item);
        abort_unless(preg_match('/^[A-Za-z0-9._-]{1,80}\.(png|jpe?g|gif|webp|bmp)$/i', $name, $m), 404);
        $path = Storage::disk('local')->path(\App\Services\Lms\MaterialPreview::dir($item).'/media/'.$name);
        abort_unless(is_file($path), 404);
        $type = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp'][strtolower($m[1])];

        return response()->file($path, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400']);
    }

    // ── Assignments ────────────────────────────────────────────────────────

    private function assignmentForStudent(CourseItem $item, User $user): array
    {
        $group = AssignmentService::settings($item)['group'] ? $this->assignments->groupFor($item, $user) : null;
        $subs = AssignmentSubmission::where('course_item_id', $item->id)
            ->when($group, fn ($q) => $q->where('course_group_id', $group), fn ($q) => $q->where('user_id', $user->id))->orderByDesc('attempt')->get();

        return [
            'due' => $this->assignments->dueFor($item, $user),
            'group' => $group ? CourseGroup::with('members:id,name')->find($group)?->only(['name', 'members']) : null,
            'submissions' => $subs->map(fn ($s) => [
                'id' => $s->id, 'attempt' => $s->attempt, 'status' => $s->status, 'submitted_at' => $s->submitted_at, 'is_late' => $s->is_late,
                'file_name' => $s->file_name, 'file_hash' => $s->file_hash, 'text' => $s->text,
                'score' => $s->released ? $s->score : null, 'raw_score' => $s->released ? $s->raw_score : null,
                'feedback_html' => $s->released ? $s->feedback_html : null, 'rubric_scores' => $s->released ? $s->rubric_scores : null,
                'checks' => self::checks($s, false),
            ]),
            'peer' => AssignmentService::settings($item)['peer_review'] ? $this->peerTasks($item, $user) : [],
        ];
    }

    private function assignmentForTeacher(CourseItem $item, User $user): array
    {
        $anonymous = AssignmentService::settings($item)['anonymous'];
        $enrolled = DB::table('enrolments')->where('course_offering_id', $item->course_offering_id)->where('status', 'active')->count();
        $subs = AssignmentSubmission::with('user:id,name,identifier')->where('course_item_id', $item->id)->where('status', '!=', 'draft')
            ->orderBy('submitted_at')->paginate(50)->through(fn ($s) => [
                'id' => $s->id, 'student' => $anonymous && ! $s->released ? 'Candidate '.$s->anon_code : $s->user?->name,
                'identifier' => $anonymous && ! $s->released ? null : $s->user?->identifier, 'attempt' => $s->attempt, 'status' => $s->status,
                'submitted_at' => $s->submitted_at, 'is_late' => $s->is_late, 'file_name' => $s->file_name, 'text' => $s->text,
                'raw_score' => $s->raw_score, 'score' => $s->score, 'feedback_html' => $s->feedback_html, 'rubric_scores' => $s->rubric_scores,
                'released' => $s->released, 'penalty' => $this->assignments->penalty($item, $s), 'group' => $s->course_group_id,
                'similarity' => $s->similarity !== null ? (float) $s->similarity : null,
                'checks' => self::checks($s, true),
                'external_check' => ($pc = \App\Models\PlagiarismCheck::where('assignment_submission_id', $s->id)->latest('id')->first()) ? $pc->only(['provider', 'status', 'score', 'report_url', 'error']) : null,
                'similarity_matches' => collect($s->similarity_matches ?? [])->map(fn ($m) => ['percent' => $m['percent'], 'submission_id' => $m['submission_id'],
                    'student' => $anonymous ? 'another submission' : (\App\Models\User::find($m['user_id'])?->name ?? 'unknown'), 'same_assignment' => $m['item_id'] === $item->id])->all(),
            ]);

        return [
            'submissions' => $subs,
            'summary' => ['enrolled' => $enrolled, 'submitted' => AssignmentSubmission::where('course_item_id', $item->id)->where('status', '!=', 'draft')->distinct()->count('user_id'),
                'marked' => AssignmentSubmission::where('course_item_id', $item->id)->whereIn('status', ['marked', 'returned'])->count(),
                'unreleased' => AssignmentSubmission::where('course_item_id', $item->id)->where('status', 'marked')->count()],
            'extensions' => DeadlineExtension::where('course_item_id', $item->id)->join('users', 'users.id', '=', 'deadline_extensions.user_id')
                ->get(['users.name', 'users.identifier', 'deadline_extensions.due_at', 'deadline_extensions.reason']),
            'groups' => CourseGroup::with('members:id,name')->where('course_offering_id', $item->course_offering_id)->get(['id', 'name']),
            'plagiarismService' => app(\App\Services\Integrations\PlagiarismConnector::class)->enabled() ? (app(\App\Services\Integrations\Connectors::class)->get('plagiarism')['name'] ?: 'Plagiarism service') : null,
        ];
    }

    /**
     * The similarity and AI-writing scores of a submission, for the student (scores and what they
     * mean) or the lecturer (also the reasons). Null fields mean "not checked yet".
     */
    public static function checks(AssignmentSubmission $s, bool $teacher): array
    {
        $external = \App\Models\PlagiarismCheck::where('assignment_submission_id', $s->id)->where('status', 'complete')->latest('id')->first();

        return [
            'checked' => $s->similarity_checked_at !== null || $s->ai_checked_at !== null,
            'checked_at' => $s->ai_checked_at ?? $s->similarity_checked_at,
            'pending' => $s->ai_checked_at === null && $s->similarity_checked_at === null && $s->submitted_at?->gt(now()->subMinutes(10)),
            'text_status' => $s->text_status, 'words' => $s->word_count,
            'similarity' => $s->similarity !== null ? (float) $s->similarity : ($s->similarity_checked_at && $s->text_status === 'read' ? 0.0 : null),
            'external' => $external ? ['provider' => $external->provider, 'score' => $external->score, 'report_url' => $teacher ? $external->report_url : null] : null,
            'ai' => $s->ai_score !== null ? (float) $s->ai_score : null,
            'ai_band' => \App\Services\Lms\WritingCheck::band($s->ai_score !== null ? (float) $s->ai_score : null),
            'ai_signals' => $teacher ? array_values((array) data_get($s->ai_signals, 'signals', [])) : [],
            'ai_model' => $teacher ? data_get($s->ai_signals, 'model') : null,
        ];
    }

    /** A lecturer runs the similarity and AI-writing checks again (after more students hand in, say). */
    public function recheck(Request $request, AssignmentSubmission $submission): RedirectResponse
    {
        $item = CourseItem::findOrFail($submission->course_item_id);
        abort_unless($this->access->teaches($request->user(), $item->offering), 403);
        app(\App\Services\Lms\Similarity::class)->run($submission);

        return back()->with('success', 'Checked again.');
    }

    public function submit(Request $request, CourseItem $item): RedirectResponse
    {
        $this->access->authorizeOpen($request->user(), $item);
        abort_unless($this->access->learns($request->user(), $item->offering), 403);
        $data = $request->validate(['text' => ['nullable', 'string', 'max:200000'], 'file' => ['nullable', 'file', 'max:512000'], 'upload_id' => ['nullable', 'uuid']]);
        // large files arrive through a resumable upload first; the finished file is attached here
        $file = $request->file('file') ?? (! empty($data['upload_id']) ? app(\App\Services\Lms\Uploads::class)->take($data['upload_id'], $request->user(), 'assignment:'.$item->id) : null);
        $sub = $this->assignments->submit($item, $request->user(), $data['text'] ?? null, $file);
        $this->content->complete($item, $request->user());
        // the similarity and AI-writing checks run after the reply so the student is not kept waiting
        dispatch(fn () => app(\App\Services\Lms\Similarity::class)->run($sub->fresh()))->afterResponse();
        // and to the institution's plagiarism service, when it is set to check every submission
        $plag = app(\App\Services\Integrations\PlagiarismConnector::class);
        if ($plag->enabled() && app(\App\Services\Integrations\Connectors::class)->get('plagiarism')['auto']) {
            dispatch(fn () => $plag->send($sub->fresh()))->afterResponse();
        }

        return back()->with('success', 'Submitted '.$sub->submitted_at->format('j M Y, H:i:s').($sub->file_hash ? ' · receipt '.substr($sub->file_hash, 0, 12) : '').($sub->is_late ? ' (late)' : '').'.');
    }

    public function submissionFile(Request $request, AssignmentSubmission $submission): StreamedResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $user = $request->user();
        $item = $submission->item;
        $mine = $submission->user_id === $user->id || ($submission->course_group_id && DB::table('course_group_members')->where('course_group_id', $submission->course_group_id)->where('user_id', $user->id)->exists());
        abort_unless($mine || $this->access->teaches($user, $item->offering), 403);
        abort_unless($submission->file_path && Storage::disk('local')->exists($submission->file_path), 404);
        $name = $mine || ! AssignmentService::settings($item)['anonymous'] ? $submission->file_name : $submission->anon_code.'.'.pathinfo($submission->file_name, PATHINFO_EXTENSION);
        // marking on screen: PDFs, pictures, audio and video open in the page instead of downloading
        $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'];
        $ext = strtolower(pathinfo((string) $submission->file_name, PATHINFO_EXTENSION));
        if ($request->boolean('inline') && isset($types[$ext])) {
            return response()->file(Storage::disk('local')->path($submission->file_path), ['Content-Type' => $types[$ext], 'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'inline; filename="'.addcslashes($name, '"\\').'"', 'Cache-Control' => 'private, max-age=300']);
        }

        return Storage::disk('local')->download($submission->file_path, $name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function mark(Request $request, AssignmentSubmission $submission): RedirectResponse
    {
        abort_unless($this->access->canGrade($request->user(), $submission->item->offering) || $this->access->role($request->user(), $submission->item->offering) === 'assistant', 403);
        $data = $request->validate(['score' => ['nullable', 'numeric', 'min:0'], 'rubric' => ['nullable', 'array'], 'rubric.*' => ['numeric'], 'feedback_html' => ['nullable', 'string', 'max:100000']]);
        $this->assignments->mark($submission, $request->user(), isset($data['score']) ? (float) $data['score'] : null, $data['rubric'] ?? null, $data['feedback_html'] ?? null);

        return back()->with('success', 'Mark saved.');
    }

    /** Send a submission back for corrections, with what to fix and a date to resubmit by. */
    public function corrections(Request $request, AssignmentSubmission $submission): RedirectResponse
    {
        abort_unless($this->access->canGrade($request->user(), $submission->item->offering), 403);
        $data = $request->validate(['feedback_html' => ['required', 'string', 'max:100000'], 'until' => ['nullable', 'date', 'after:now']]);
        $this->assignments->requestCorrections($submission, $request->user(), app(\App\Services\Questions\RichTextSanitizer::class)->clean($data['feedback_html']),
            isset($data['until']) ? \Illuminate\Support\Carbon::parse($data['until']) : null);

        return back()->with('success', 'Sent back for corrections. The student has been told.');
    }

    public function release(Request $request, CourseItem $item): RedirectResponse
    {
        abort_unless($this->access->canGrade($request->user(), $item->offering), 403, 'Teaching assistants cannot release marks.');
        $n = $this->assignments->release($item, $request->user());

        return back()->with('success', "Released $n marks to students.");
    }

    public function extend(Request $request, CourseItem $item): RedirectResponse
    {
        abort_unless($this->access->canGrade($request->user(), $item->offering), 403);
        $data = $request->validate(['identifier' => ['required', 'string'], 'due_at' => ['required', 'date', 'after:now'], 'reason' => ['required', 'string', 'max:500']]);
        $student = User::where('identifier', $data['identifier'])->orWhere('email', $data['identifier'])->firstOrFail();
        abort_unless($this->access->learns($student, $item->offering), 422, 'That student is not registered for this course.');
        $this->assignments->extend($item, $student, CarbonImmutable::parse($data['due_at']), $data['reason'], $request->user());

        return back()->with('success', "Extension granted to {$student->name}.");
    }

    public function saveGroups(Request $request, CourseItem $item): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $item->offering);
        $data = $request->validate(['size' => ['required', 'integer', 'min:2', 'max:20']]);
        // Random groups of the chosen size from the active class list (LMS 37, 57)
        $students = DB::table('enrolments')->where('course_offering_id', $item->course_offering_id)->where('status', 'active')->pluck('user_id')->shuffle();
        DB::transaction(function () use ($students, $data, $item) {
            CourseGroup::where('course_offering_id', $item->course_offering_id)->delete();
            foreach ($students->chunk($data['size'])->values() as $i => $chunk) {
                $g = CourseGroup::create(['course_offering_id' => $item->course_offering_id, 'name' => 'Group '.($i + 1)]);
                $g->members()->attach($chunk->all());
            }
        });

        return back()->with('success', 'Groups created.');
    }

    private function peerTasks(CourseItem $item, User $user): array
    {
        // Each student reviews up to two classmates' submissions, assigned deterministically (LMS 69)
        $others = AssignmentSubmission::where('course_item_id', $item->id)->where('user_id', '!=', $user->id)->where('status', '!=', 'draft')
            ->orderByRaw('(id * 7919 + ?) % 1009', [$user->id])->limit(2)->get(['id', 'anon_code', 'text', 'file_name']);

        return $others->map(fn ($s) => ['id' => $s->id, 'code' => $s->anon_code, 'text' => $s->text, 'file_name' => $s->file_name,
            'mine' => PeerReview::where('assignment_submission_id', $s->id)->where('reviewer_id', $user->id)->first(['score', 'comment'])])->all();
    }

    public function peerReview(Request $request, AssignmentSubmission $submission): RedirectResponse
    {
        $item = $submission->item;
        abort_unless(AssignmentService::settings($item)['peer_review'] && $this->access->learns($request->user(), $item->offering), 403);
        abort_unless(collect($this->peerTasks($item, $request->user()))->pluck('id')->contains($submission->id) || $submission->user_id === $request->user()->id, 403);
        $data = $request->validate(['score' => ['nullable', 'numeric', 'min:0', 'max:'.AssignmentService::settings($item)['max_score']], 'comment' => ['required', 'string', 'max:3000']]);
        PeerReview::updateOrCreate(['assignment_submission_id' => $submission->id, 'reviewer_id' => $request->user()->id],
            $data + ['is_self' => $submission->user_id === $request->user()->id]);

        return back()->with('success', 'Review saved.');
    }

    // ── Quizzes (exam engine) ──────────────────────────────────────────────

    public function startQuiz(Request $request, CourseItem $item): RedirectResponse
    {
        $this->access->authorizeOpen($request->user(), $item);
        abort_unless($item->type === 'quiz' && $this->access->learns($request->user(), $item->offering), 403);
        $candidate = $this->content->quizCandidate($item, $request->user());
        $this->content->complete($item, $request->user());

        return redirect()->route('candidate.room', $candidate);
    }

    public function publishQuiz(Request $request, CourseItem $item): RedirectResponse
    {
        abort_unless($this->access->canGrade($request->user(), $item->offering), 403);
        $this->content->publishQuiz($item, $request->user());

        return back()->with('success', 'Quiz published.');
    }

    /** A lecturer sets up the stations and marking of a practical test in their course (before it is published). */
    public function savePractical(Request $request, CourseItem $item): RedirectResponse
    {
        abort_unless($item->type === 'quiz' && $this->access->canGrade($request->user(), $item->offering), 403);
        $exam = $item->exam;
        abort_unless($exam && $exam->type === 'practical', 404);
        abort_unless($exam->status === 'draft', 422, 'This practical is published, so its marking is fixed.');
        app(\App\Services\Exams\PracticalSetup::class)->save($exam, $request->validate(\App\Services\Exams\PracticalSetup::rules()));

        return back()->with('success', 'Practical saved.');
    }

    /** Lecturers build their own quizzes from the question bank (their course's questions, or any approved ones). */
    public function quizQuestions(Request $request, CourseItem $item)
    {
        abort_unless($item->type === 'quiz' && $this->access->canGrade($request->user(), $item->offering), 403);
        $exam = $item->exam;
        $attached = DB::table('exam_section_questions')->join('exam_sections', 'exam_sections.id', '=', 'exam_section_questions.exam_section_id')
            ->join('questions', 'questions.id', '=', 'exam_section_questions.question_id')->where('exam_sections.exam_id', $exam->id)
            ->orderBy('exam_section_questions.position')->get(['questions.id', 'questions.type', 'questions.stem_html', 'questions.mark']);
        $q = trim((string) $request->query('q'));
        $found = \App\Models\Question::query()->whereIn('status', ['approved', 'draft'])
            ->where(fn ($w) => $w->where('course_id', $item->offering->course_id)->orWhere('created_by', $request->user()->id)->orWhereNull('course_id'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('stem_html', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('topic', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->whereNotIn('id', $attached->pluck('id'))->latest('id')->limit(30)->get(['id', 'type', 'stem_html', 'mark', 'topic']);

        return response()->json(['attached' => $attached, 'found' => $found, 'editable' => $exam->status === 'draft']);
    }

    public function quizAttach(Request $request, CourseItem $item): RedirectResponse
    {
        abort_unless($item->type === 'quiz' && $this->access->canGrade($request->user(), $item->offering), 403);
        $exam = $item->exam;
        abort_unless($exam->status === 'draft', 422, 'This quiz is published, so its questions are frozen. Create a new quiz to change them.');
        $data = $request->validate(['attach' => ['array'], 'attach.*' => ['integer'], 'detach' => ['array'], 'detach.*' => ['integer']]);
        $section = $exam->sections()->orderBy('position')->first() ?? $exam->sections()->create(['title' => 'Questions', 'position' => 0]);
        $allowed = \App\Models\Question::whereIn('id', $data['attach'] ?? [])->pluck('id');
        $pos = (int) DB::table('exam_section_questions')->where('exam_section_id', $section->id)->max('position');
        foreach ($allowed as $qid) {
            $section->questions()->syncWithoutDetaching([$qid => ['position' => ++$pos]]);
        }
        if (! empty($data['detach'])) {
            $section->questions()->detach($data['detach']);
        }

        return back();
    }

    // ── Forums ─────────────────────────────────────────────────────────────

    public function thread(Request $request, ForumThread $thread): Response
    {
        $this->access->authorizeOpen($request->user(), $thread->item);
        $teacher = $this->access->teaches($request->user(), $thread->item->offering);

        return Inertia::render('learn/thread', [
            'thread' => ['id' => $thread->id, 'title' => $thread->title, 'locked' => $thread->locked, 'pinned' => $thread->pinned, 'item_id' => $thread->course_item_id, 'item' => $thread->item->title],
            'posts' => ForumPost::with('user:id,name,user_type')->where('forum_thread_id', $thread->id)->orderBy('id')->paginate(50)
                ->through(fn ($p) => ['id' => $p->id, 'author' => $p->user?->name, 'staff' => $p->user?->user_type === 'staff', 'body_html' => $p->body_html,
                    'created_at' => $p->created_at, 'is_answer' => $p->is_answer, 'mine' => $p->user_id === $request->user()->id, 'parent_id' => $p->parent_id]),
            'teacher' => $teacher,
        ]);
    }

    public function storeThread(Request $request, CourseItem $item, RichTextSanitizer $sanitizer): RedirectResponse
    {
        $this->access->authorizeOpen($request->user(), $item);
        abort_unless($item->type === 'forum', 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:190'], 'body_html' => ['required', 'string', 'max:50000']]);
        $thread = DB::transaction(function () use ($item, $data, $request, $sanitizer) {
            $t = ForumThread::create(['course_item_id' => $item->id, 'user_id' => $request->user()->id, 'title' => $data['title'], 'posts_count' => 1, 'last_post_at' => now()]);
            ForumPost::create(['forum_thread_id' => $t->id, 'user_id' => $request->user()->id, 'body_html' => $sanitizer->clean($data['body_html'])]);

            return $t;
        });
        $this->content->complete($item, $request->user());

        return redirect("/learn/threads/{$thread->id}");
    }

    public function reply(Request $request, ForumThread $thread, RichTextSanitizer $sanitizer): RedirectResponse
    {
        $user = $request->user();
        $this->access->authorizeOpen($user, $thread->item);
        $teacher = $this->access->teaches($user, $thread->item->offering);
        abort_if($thread->locked && ! $teacher, 403, 'This discussion is closed.');
        $data = $request->validate(['body_html' => ['required', 'string', 'max:50000'], 'parent_id' => ['nullable', 'integer']]);
        DB::transaction(function () use ($thread, $data, $user, $sanitizer) {
            ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $user->id, 'parent_id' => $data['parent_id'] ?? null, 'body_html' => $sanitizer->clean($data['body_html'])]);
            $thread->increment('posts_count', 1, ['last_post_at' => now()]);
        });
        if ($thread->user_id !== $user->id) {
            Notification::send($thread->user, new LmsNotice('New reply', "{$user->name} replied to \"{$thread->title}\".", "/learn/threads/{$thread->id}"));
        }

        return back();
    }

    public function moderate(Request $request, ForumThread $thread): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $thread->item->offering);
        $data = $request->validate(['pinned' => ['sometimes', 'boolean'], 'locked' => ['sometimes', 'boolean'], 'answer_post_id' => ['sometimes', 'integer'], 'delete_post_id' => ['sometimes', 'integer']]);
        $thread->update(array_intersect_key($data, array_flip(['pinned', 'locked'])));
        if (isset($data['answer_post_id'])) {
            ForumPost::where('forum_thread_id', $thread->id)->whereKey($data['answer_post_id'])->update(['is_answer' => true]);
        }
        if (isset($data['delete_post_id'])) {
            ForumPost::where('forum_thread_id', $thread->id)->whereKey($data['delete_post_id'])->first()?->delete();
        }

        return back();
    }
}
