<?php

namespace App\Services\Lms;

use App\Models\Announcement;
use App\Models\CourseItem;
use App\Models\CourseItemRevision;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Questions\RichTextSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Course pages: sections, materials, activities, revisions, completion, course copy,
 * quizzes on the exam engine, and announcements (LMS 26-42, 180).
 */
class CourseContentService
{
    public const MATERIAL_TYPES = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'rtf', 'odt', 'odp', 'csv', 'zip',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp3', 'm4a', 'wav', 'ogg', 'mp4', 'webm', 'mov'];

    public function __construct(private AuditLogger $audit, private RichTextSanitizer $sanitizer) {}

    public function saveItem(CourseOffering $offering, array $data, ?UploadedFile $file, User $by, ?CourseItem $item = null): CourseItem
    {
        $section = CourseSection::where('course_offering_id', $offering->id)->findOrFail($data['course_section_id'] ?? $item?->course_section_id);
        if (! empty($data['prerequisite_item_id'])) {
            abort_unless(CourseItem::where('course_offering_id', $offering->id)->whereKey($data['prerequisite_item_id'])->exists(), 422);
        }
        if (isset($data['body_html'])) {
            $data['body_html'] = $this->sanitizer->clean($data['body_html']);
        }
        if (($data['type'] ?? $item?->type) === 'link' || ($data['type'] ?? $item?->type) === 'live' || ($data['type'] ?? $item?->type) === 'video') {
            if (! empty($data['url']) && ! preg_match('#^https://#i', $data['url'])) {
                throw ValidationException::withMessages(['url' => 'Links must start with https://']);
            }
        }

        return DB::transaction(function () use ($offering, $section, $data, $file, $by, $item) {
            $item ??= new CourseItem(['course_offering_id' => $offering->id, 'created_by' => $by->id,
                'position' => (int) CourseItem::where('course_section_id', $section->id)->max('position') + 1]);
            if ($item->exists) {
                CourseItemRevision::create(['course_item_id' => $item->id, 'version' => $item->version, 'edited_by' => $by->id, 'created_at' => now(),
                    'snapshot' => Arr::only($item->getAttributes(), ['title', 'body_html', 'url', 'file_name', 'file_path', 'release_at', 'due_at', 'settings', 'visible'])]);
                $item->version++;
            }
            $item->fill(Arr::only($data, ['type', 'title', 'body_html', 'url', 'release_at', 'due_at', 'cutoff_at', 'visible', 'requires_completion', 'prerequisite_item_id', 'exam_id']));
            $item->course_section_id = $section->id;
            if (isset($data['settings'])) {
                $item->settings = array_replace($item->settings ?? [], $data['settings']);
            }
            if ($file) {
                $ext = strtolower($file->getClientOriginalExtension());
                if (! in_array($ext, self::MATERIAL_TYPES, true)) {
                    throw ValidationException::withMessages(['file' => 'This file type cannot be uploaded.']);
                }
                // Files are private; students download through an access-checked route
                $item->fill(['file_path' => $file->storeAs("lms/{$offering->tenant_id}/materials/{$offering->id}", Str::uuid().'.'.$ext, 'local'),
                    'file_name' => Str::limit($file->getClientOriginalName(), 200, ''), 'file_mime' => $file->getMimeType(), 'file_size' => $file->getSize()]);
            }
            if ($item->type === 'quiz' && ! $item->exam_id) {
                $item->exam_id = Exam::create(['title' => $item->title, 'type' => 'cbt', 'category' => 'quiz', 'status' => 'draft', 'course_id' => $offering->course_id,
                    'semester_id' => $offering->semester_id, 'duration_minutes' => (int) ($data['settings']['duration'] ?? 30), 'max_attempts' => (int) ($data['settings']['attempts'] ?? 1),
                    'total_mark' => (float) ($data['settings']['max_score'] ?? 20), 'created_by' => $by->id,
                    'settings' => ['shuffle_questions' => true, 'shuffle_options' => true, 'show_score' => true, 'require_check_in' => false],
                    'proctoring' => ['require_consent' => false, 'violation_action' => 'warn'], 'channels' => ['web', 'desktop', 'seb']])->id;
                Exam::find($item->exam_id)->sections()->create(['title' => 'Questions', 'position' => 0]);
            }
            $item->save();
            $this->audit->record($item->wasRecentlyCreated ? 'lms.item.created' : 'lms.item.updated', $item, ['version' => $item->version], $by->id);

            if ($item->wasRecentlyCreated && $item->visible && (! $item->release_at || $item->release_at->isPast())) {
                $this->notifyStudents($offering, "New in {$offering->course->code}: {$item->title}", Str::ucfirst($item->type).' added.', "/learn/items/{$item->id}");
            }

            return $item;
        });
    }

    public function complete(CourseItem $item, User $user): void
    {
        DB::table('item_completions')->insertOrIgnore(['course_item_id' => $item->id, 'user_id' => $user->id, 'completed_at' => now()]);
    }

    /** Percent of required, released items this student has completed (LMS 38, 49). */
    public function progress(CourseOffering $offering, User $user): array
    {
        $required = CourseItem::where('course_offering_id', $offering->id)->where('visible', true)->where('requires_completion', true)
            ->where(fn ($q) => $q->whereNull('release_at')->orWhere('release_at', '<=', now()))->pluck('id');
        $done = DB::table('item_completions')->where('user_id', $user->id)->whereIn('course_item_id', $required)->count();

        return ['done' => $done, 'total' => $required->count(), 'percent' => $required->count() ? (int) round($done / $required->count() * 100) : 0];
    }

    /** Copy sections and materials from a previous offering (LMS 27, 39); dates shift with the new semester. */
    public function copyFrom(CourseOffering $from, CourseOffering $to, User $by): int
    {
        abort_unless($from->course_id === $to->course_id || $by->can('academics.manage'), 403);
        $shift = $from->semester?->starts_on && $to->semester?->starts_on ? $from->semester->starts_on->diffInSeconds($to->semester->starts_on, false) : 0;
        $n = 0;
        DB::transaction(function () use ($from, $to, $by, $shift, &$n) {
            $map = [];
            foreach ($from->sections()->get() as $section) {
                $new = $section->replicate(['course_offering_id'])->fill(['course_offering_id' => $to->id, 'release_at' => $section->release_at?->addSeconds($shift)]);
                $new->save();
                foreach ($section->items()->orderBy('position')->get() as $item) {
                    $copy = $item->replicate(['course_offering_id', 'course_section_id', 'exam_id', 'prerequisite_item_id'])->fill([
                        'course_offering_id' => $to->id, 'course_section_id' => $new->id, 'version' => 1, 'created_by' => $by->id,
                        'release_at' => $item->release_at?->addSeconds($shift), 'due_at' => $item->due_at?->addSeconds($shift), 'cutoff_at' => $item->cutoff_at?->addSeconds($shift),
                        'exam_id' => $item->exam_id ? $this->copyQuizExam($item->exam_id, $to)?->id : null,
                    ]);
                    if ($item->file_path && Storage::disk('local')->exists($item->file_path)) {
                        $path = "lms/{$to->tenant_id}/materials/{$to->id}/".Str::uuid().'.'.pathinfo($item->file_path, PATHINFO_EXTENSION);
                        Storage::disk('local')->copy($item->file_path, $path);
                        $copy->file_path = $path;
                    }
                    $copy->save();
                    $map[$item->id] = $copy;
                    $n++;
                }
            }
            foreach ($from->items()->whereNotNull('prerequisite_item_id')->get() as $item) {
                if (isset($map[$item->id], $map[$item->prerequisite_item_id])) {
                    $map[$item->id]->update(['prerequisite_item_id' => $map[$item->prerequisite_item_id]->id]);
                }
            }
            $to->update(['copied_from_id' => $from->id, 'outline_html' => $to->outline_html ?: $from->outline_html]);
        });
        $this->audit->record('lms.course.copied', $to, ['from' => $from->id, 'items' => $n], $by->id);

        return $n;
    }

    private function copyQuizExam(int $examId, CourseOffering $to): ?Exam
    {
        $exam = Exam::with('sections.questions')->find($examId);
        if (! $exam) {
            return null;
        }
        $copy = $exam->replicate(['status', 'approved_at', 'approved_by', 'legacy_ref'])->fill(['status' => 'draft', 'semester_id' => $to->semester_id]);
        $copy->save();
        foreach ($exam->sections as $section) {
            $s = $copy->sections()->create($section->only(['title', 'instructions', 'position', 'blueprint', 'answer_any']));
            foreach ($section->questions as $q) {
                $s->questions()->attach($q->id, ['position' => $q->pivot->position, 'mark' => $q->pivot->mark]);
            }
        }

        return $copy;
    }

    /**
     * Quizzes run on the exam engine (same timer, autosave, equations, proctoring).
     * Each quiz gets one remote sitting covering its release → due window; enrolled
     * students are added as candidates the first time they open it.
     */
    public function quizCandidate(CourseItem $item, User $student): ExamCandidate
    {
        $exam = Exam::findOrFail($item->exam_id);
        if (! in_array($exam->status, ['scheduled', 'live'], true)) {
            throw ValidationException::withMessages(['quiz' => 'This quiz has not been published yet.']);
        }
        $session = ExamSession::firstOrCreate(['exam_id' => $exam->id, 'name' => 'Course quiz'], [
            'mode' => 'remote', 'starts_at' => $item->release_at ?? now()->subMinute(), 'ends_at' => $item->due_at ?? now()->addYear(),
            'late_entry_minutes' => 65535, 'status' => 'open',
        ]);

        return ExamCandidate::firstOrCreate(['exam_session_id' => $session->id, 'user_id' => $student->id], ['consent_given' => false]);
    }

    /** Lecturers publish their own quizzes; central exams still go through the exams office (LMS 75). */
    public function publishQuiz(CourseItem $item, User $by): void
    {
        $exam = Exam::findOrFail($item->exam_id);
        abort_unless($exam->category === 'quiz', 403, 'Central examinations are published by the exams office.');
        if (! DB::table('exam_section_questions')->join('exam_sections', 'exam_sections.id', '=', 'exam_section_questions.exam_section_id')->where('exam_sections.exam_id', $exam->id)->exists()
            && ! $exam->sections()->whereNotNull('blueprint')->exists()) {
            throw ValidationException::withMessages(['quiz' => 'Add questions to the quiz first.']);
        }
        $exam->update(['status' => 'scheduled', 'approved_at' => now(), 'approved_by' => $by->id]);
        ExamSession::where('exam_id', $exam->id)->update(['starts_at' => $item->release_at ?? now()->subMinute(), 'ends_at' => $item->due_at ?? now()->addYear()]);
        $this->audit->record('lms.quiz.published', $item, ['exam' => $exam->id], $by->id);
    }

    public function announce(array $data, User $by): Announcement
    {
        $a = Announcement::create([
            'scope' => $data['scope'], 'scope_id' => $data['scope_id'] ?? null, 'title' => $data['title'],
            'body_html' => $this->sanitizer->clean($data['body_html']), 'urgent' => (bool) ($data['urgent'] ?? false),
            'publish_at' => $data['publish_at'] ?? now(), 'author_id' => $by->id,
        ]);
        $users = $this->audience($a);
        $a->update(['recipients' => $users->count()]);
        if ($a->publish_at->isPast()) {
            foreach ($users->chunk(500) as $chunk) {
                Notification::send(User::whereIn('id', $chunk)->get(), new LmsNotice($a->title, Str::limit(strip_tags($a->body_html), 180), '/learn/announcements', $a->urgent, category: 'announcements'));
            }
        }
        $this->audit->record('lms.announcement', $a, ['scope' => $a->scope, 'scope_id' => $a->scope_id, 'recipients' => $users->count()], $by->id);

        return $a;
    }

    /** User ids an announcement reaches. */
    public function audience(Announcement $a)
    {
        $active = fn ($q) => $q->whereIn('status', ['active']);

        return match ($a->scope) {
            'offering' => DB::table('enrolments')->where('course_offering_id', $a->scope_id)->where($active)->pluck('user_id'),
            'department' => DB::table('enrolments')->join('course_offerings', 'course_offerings.id', '=', 'enrolments.course_offering_id')
                ->join('courses', 'courses.id', '=', 'course_offerings.course_id')->where('courses.department_id', $a->scope_id)
                ->where('enrolments.status', 'active')->distinct()->pluck('enrolments.user_id'),
            'faculty' => DB::table('enrolments')->join('course_offerings', 'course_offerings.id', '=', 'enrolments.course_offering_id')
                ->join('courses', 'courses.id', '=', 'course_offerings.course_id')->join('departments', 'departments.id', '=', 'courses.department_id')
                ->where('departments.faculty_id', $a->scope_id)->where('enrolments.status', 'active')->distinct()->pluck('enrolments.user_id'),
            default => DB::table('users')->where('tenant_id', $a->tenant_id)->where('status', 'active')->pluck('id'),
        };
    }

    public function notifyStudents(CourseOffering $offering, string $title, string $body, string $url): void
    {
        DB::table('enrolments')->where('course_offering_id', $offering->id)->where('status', 'active')->orderBy('id')
            ->chunk(500, fn ($rows) => Notification::send(User::whereIn('id', $rows->pluck('user_id'))->get(), new LmsNotice($title, $body, $url)));
    }
}
