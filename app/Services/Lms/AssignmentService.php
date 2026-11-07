<?php

namespace App\Services\Lms;

use App\Models\AssignmentSubmission;
use App\Models\CourseItem;
use App\Models\DeadlineExtension;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Questions\RichTextSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Assignments (LMS 56-57, 63-67, 69): text and file submissions, groups, attempts,
 * due dates with per-student extensions, late penalties and a hard cut-off,
 * rubric marking, anonymous marking and controlled release of marks.
 */
class AssignmentService
{
    public const DEFAULT_TYPES = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'rtf', 'odt', 'jpg', 'jpeg', 'png', 'zip', 'mp4', 'mp3', 'py', 'java', 'c', 'cpp', 'js', 'ts', 'php', 'ipynb', 'm', 'r'];

    public function __construct(private AuditLogger $audit, private RichTextSanitizer $sanitizer, private GradebookService $gradebook) {}

    public static function settings(CourseItem $item): array
    {
        return array_replace([
            'max_score' => 100, 'max_attempts' => 1, 'allow_late' => true, 'late_penalty_pct' => 10, 'max_penalty_pct' => 50,
            'penalty_per' => 'day', 'group' => false, 'anonymous' => false, 'text' => true, 'file' => true,
            'file_types' => self::DEFAULT_TYPES, 'max_mb' => 20, 'rubric' => [], 'peer_review' => false,
        ], $item->settings ?? []);
    }

    public function dueFor(CourseItem $item, User $user): ?CarbonInterface
    {
        return DeadlineExtension::where('course_item_id', $item->id)->where('user_id', $user->id)->first()?->due_at ?? $item->due_at;
    }

    public function groupFor(CourseItem $item, User $user): ?int
    {
        return DB::table('course_group_members')->join('course_groups', 'course_groups.id', '=', 'course_group_members.course_group_id')
            ->where('course_groups.course_offering_id', $item->course_offering_id)->where('course_group_members.user_id', $user->id)->value('course_groups.id');
    }

    public function submit(CourseItem $item, User $user, ?string $text, ?UploadedFile $file): AssignmentSubmission
    {
        abort_unless($item->type === 'assignment', 404);
        $s = self::settings($item);
        $now = now();
        $due = $this->dueFor($item, $user);
        $extended = $due && $item->due_at && ! $due->equalTo($item->due_at);

        if ($item->cutoff_at && $now->greaterThan($item->cutoff_at) && ! ($extended && $now->lessThanOrEqualTo($due))) {
            throw ValidationException::withMessages(['submission' => 'Submissions closed on '.$item->cutoff_at->format('j M Y, H:i').'.']);
        }
        if ($due && $now->greaterThan($due) && ! $s['allow_late']) {
            throw ValidationException::withMessages(['submission' => 'The deadline has passed and late submissions are not accepted.']);
        }
        $text = $s['text'] ? $this->sanitizer->clean($text) : null;
        if (! $file && trim(strip_tags((string) $text)) === '') {
            throw ValidationException::withMessages(['submission' => 'Add text or attach a file.']);
        }
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (! $s['file'] || ! in_array($ext, $s['file_types'], true)) {
                throw ValidationException::withMessages(['file' => 'This file type is not accepted ('.implode(', ', $s['file_types']).').']);
            }
            if ($file->getSize() > $s['max_mb'] * 1024 * 1024) {
                throw ValidationException::withMessages(['file' => "Files must be under {$s['max_mb']} MB."]);
            }
        }

        $group = $s['group'] ? $this->groupFor($item, $user) : null;
        if ($s['group'] && ! $group) {
            throw ValidationException::withMessages(['submission' => 'You are not in a group for this assignment yet. Ask your lecturer.']);
        }

        return DB::transaction(function () use ($item, $user, $text, $file, $s, $now, $due, $group) {
            // One submission per group for group work; per student otherwise
            $previous = AssignmentSubmission::where('course_item_id', $item->id)
                ->when($group, fn ($q) => $q->where('course_group_id', $group), fn ($q) => $q->where('user_id', $user->id))
                ->lockForUpdate()->orderByDesc('attempt')->first();
            if ($previous && in_array($previous->status, ['marked', 'returned'], true) && $previous->attempt >= $s['max_attempts']) {
                throw ValidationException::withMessages(['submission' => 'This assignment has already been marked.']);
            }
            $attempt = $previous ? ($previous->status === 'draft' ? $previous->attempt : $previous->attempt + 1) : 1;
            if ($attempt > $s['max_attempts']) {
                $attempt = $previous->attempt; // resubmission replaces the last attempt until the deadline
                if ($due && now()->greaterThan($due)) {
                    throw ValidationException::withMessages(['submission' => 'You have used all your attempts.']);
                }
            }

            $sub = AssignmentSubmission::firstOrNew(['course_item_id' => $item->id, 'user_id' => $previous?->user_id ?? $user->id, 'attempt' => $attempt]);
            if ($file) {
                if ($sub->file_path) {
                    Storage::disk('local')->delete($sub->file_path);
                }
                $path = $file->storeAs("lms/{$item->tenant_id}/submissions/{$item->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
                $sub->fill(['file_path' => $path, 'file_name' => Str::limit($file->getClientOriginalName(), 200, ''), 'file_mime' => $file->getMimeType(),
                    'file_size' => $file->getSize(), 'file_hash' => hash_file('sha256', $file->getRealPath())]);
            }
            $sub->fill([
                'course_group_id' => $group, 'text' => $text, 'submitted_at' => $now, 'is_late' => $due && $now->greaterThan($due),
                'status' => 'submitted', 'anon_code' => $sub->anon_code ?: strtoupper(Str::random(6)),
            ])->save();
            $this->audit->record('assignment.submitted', $sub, ['item' => $item->id, 'attempt' => $attempt, 'late' => $sub->is_late, 'hash' => $sub->file_hash]);

            return $sub;
        });
    }

    /** Late penalty: % per day (or flat), capped. */
    public function penalty(CourseItem $item, AssignmentSubmission $sub): float
    {
        $s = self::settings($item);
        if (! $sub->is_late || ! $sub->submitted_at) {
            return 0;
        }
        $due = $this->dueFor($item, $sub->user) ?? $item->due_at;
        $days = max(1, (int) ceil($due->diffInSeconds($sub->submitted_at) / 86400));
        $pct = $s['penalty_per'] === 'flat' ? $s['late_penalty_pct'] : $s['late_penalty_pct'] * $days;

        return (float) min($s['max_penalty_pct'], $pct);
    }

    public function mark(AssignmentSubmission $sub, User $marker, ?float $score, ?array $rubric, ?string $feedback): AssignmentSubmission
    {
        $item = $sub->item;
        $s = self::settings($item);
        if ($rubric && $s['rubric']) {
            $score = 0;
            foreach ($s['rubric'] as $i => $criterion) {
                $levels = collect($criterion['levels'] ?? [])->pluck('points')->map(fn ($p) => (float) $p);
                $chosen = (float) ($rubric[$i] ?? 0);
                if (! $levels->contains($chosen)) {
                    throw ValidationException::withMessages(['rubric' => "Choose one of the levels for \"{$criterion['criterion']}\"."]);
                }
                $score += $chosen;
            }
        }
        if ($score === null || $score < 0 || $score > $s['max_score']) {
            throw ValidationException::withMessages(['score' => "Give a mark between 0 and {$s['max_score']}."]);
        }
        $final = round($score * (1 - $this->penalty($item, $sub) / 100), 2);
        $before = $sub->only(['score', 'raw_score', 'status']);
        $sub->update([
            'raw_score' => $score, 'score' => $final, 'rubric_scores' => $rubric, 'feedback_html' => $this->sanitizer->clean($feedback),
            'marked_by' => $marker->id, 'marked_at' => now(), 'status' => 'marked',
        ]);
        // Group work: everyone in the group gets the same mark
        if ($sub->course_group_id) {
            AssignmentSubmission::where('course_item_id', $item->id)->where('course_group_id', $sub->course_group_id)->where('id', '!=', $sub->id)
                ->update(['raw_score' => $score, 'score' => $final, 'status' => 'marked', 'marked_by' => $marker->id, 'marked_at' => now()]);
        }
        $this->audit->record('assignment.marked', $sub, ['before' => $before, 'score' => $final, 'penalty' => $this->penalty($item, $sub)]);
        if ($sub->released) {
            $this->gradebook->syncItem($item);
        }

        return $sub;
    }

    /** Release marked work to students (LMS 72): notifies them and feeds the gradebook. */
    public function release(CourseItem $item, User $by): int
    {
        $subs = AssignmentSubmission::where('course_item_id', $item->id)->where('status', 'marked')->where('released', false)->get();
        if ($subs->isEmpty()) {
            return 0;
        }
        AssignmentSubmission::whereIn('id', $subs->pluck('id'))->update(['released' => true, 'status' => 'returned']);
        $recipients = $this->recipients($item, $subs);
        Notification::send($recipients, new LmsNotice('Assignment marked', "Your mark for \"{$item->title}\" is available.", "/learn/items/{$item->id}"));
        $this->gradebook->syncItem($item);
        $this->audit->record('assignment.released', $item, ['count' => $subs->count()], $by->id);

        return $subs->count();
    }

    public function extend(CourseItem $item, User $student, CarbonInterface $due, string $reason, User $by): DeadlineExtension
    {
        $ext = DeadlineExtension::updateOrCreate(['course_item_id' => $item->id, 'user_id' => $student->id], ['due_at' => $due, 'reason' => $reason, 'granted_by' => $by->id]);
        $this->audit->record('assignment.extension', $item, ['student' => $student->id, 'due' => $due->toIso8601String(), 'reason' => $reason], $by->id);
        $student->notify(new LmsNotice('Deadline extended', "Your deadline for \"{$item->title}\" is now ".$due->format('j M Y, H:i').'.', "/learn/items/{$item->id}"));

        return $ext;
    }

    private function recipients(CourseItem $item, $subs)
    {
        $groupIds = $subs->pluck('course_group_id')->filter();
        $ids = $subs->pluck('user_id')->merge($groupIds->isEmpty() ? [] : DB::table('course_group_members')->whereIn('course_group_id', $groupIds)->pluck('user_id'));

        return User::whereIn('id', $ids->unique())->get();
    }
}
