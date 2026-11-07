<?php

namespace App\Services\Lms;

use App\Models\AssignmentSubmission;
use App\Models\CourseGrade;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\GradeCategory;
use App\Models\GradeItem;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\Marker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Course gradebook (LMS 71, 147-154, 162): assignments, quizzes, attendance, manual marks and
 * central exam results feed weighted categories (e.g. CA 30 %, Examination 70 %).
 * Course grades go draft → approved (HOD/Dean) → released, and are locked once released.
 */
class GradebookService
{
    public function __construct(private AuditLogger $audit, private Marker $marker) {}

    public function ensureDefaults(CourseOffering $offering): void
    {
        if (! GradeCategory::where('course_offering_id', $offering->id)->exists()) {
            GradeCategory::create(['course_offering_id' => $offering->id, 'name' => 'Continuous assessment', 'weight' => 30, 'position' => 0]);
            GradeCategory::create(['course_offering_id' => $offering->id, 'name' => 'Examination', 'weight' => 70, 'position' => 1]);
        }
    }

    public function saveCategories(CourseOffering $offering, array $categories): void
    {
        $total = array_sum(array_column($categories, 'weight'));
        if (abs($total - 100) > 0.01) {
            throw ValidationException::withMessages(['categories' => "Weights must add up to 100 (they add up to {$total})."]);
        }
        DB::transaction(function () use ($offering, $categories) {
            $keep = [];
            foreach (array_values($categories) as $i => $c) {
                $cat = isset($c['id']) ? GradeCategory::where('course_offering_id', $offering->id)->findOrFail($c['id']) : new GradeCategory(['course_offering_id' => $offering->id]);
                $cat->fill(['name' => $c['name'], 'weight' => $c['weight'], 'aggregation' => $c['aggregation'] ?? 'mean', 'best_n' => $c['best_n'] ?? null, 'position' => $i])->save();
                $keep[] = $cat->id;
            }
            $orphans = GradeCategory::where('course_offering_id', $offering->id)->whereNotIn('id', $keep)->pluck('id');
            if ($orphans->isNotEmpty()) {
                GradeItem::whereIn('grade_category_id', $orphans)->update(['grade_category_id' => $keep[0]]);
                GradeCategory::whereIn('id', $orphans)->delete();
            }
        });
        $this->audit->record('gradebook.categories', $offering, ['categories' => $categories]);
    }

    /** Assignment or quiz → grade item + entries. */
    public function syncItem(CourseItem $item): void
    {
        if (! in_array($item->type, ['assignment', 'quiz'], true)) {
            return;
        }
        $offering = $item->offering;
        $this->ensureDefaults($offering);
        $category = $item->settings['grade_category_id'] ?? GradeCategory::where('course_offering_id', $offering->id)->orderBy('position')->value('id');
        $exam = $item->type === 'quiz' && $item->exam_id ? Exam::find($item->exam_id) : null;
        $max = $item->type === 'assignment' ? (float) AssignmentService::settings($item)['max_score'] : (float) ($exam?->total_mark ?: 100);
        $gi = GradeItem::updateOrCreate(['course_offering_id' => $offering->id, 'source' => 'item', 'source_id' => $item->id],
            ['grade_category_id' => $category, 'title' => $item->title, 'max_score' => $max]);

        $scores = $item->type === 'assignment' ? $this->assignmentScores($item) : $this->examScores($exam, true);
        $this->writeEntries($gi, $scores);
    }

    /** Central exams for this course and semester → "Examination" category (LMS 147, 158). */
    public function syncExams(CourseOffering $offering): void
    {
        $this->ensureDefaults($offering);
        $category = GradeCategory::where('course_offering_id', $offering->id)->orderByDesc('position')->value('id');
        $exams = Exam::where('course_id', $offering->course_id)->where('category', 'exam')
            ->where(fn ($q) => $q->where('semester_id', $offering->semester_id)->orWhereNull('semester_id'))->get();
        foreach ($exams as $exam) {
            $gi = GradeItem::firstOrCreate(['course_offering_id' => $offering->id, 'source' => 'exam', 'source_id' => $exam->id],
                ['grade_category_id' => $category, 'title' => $exam->title, 'max_score' => (float) ($exam->total_mark ?: 100)]);
            $this->writeEntries($gi, $this->examScores($exam, false));
        }
    }

    public function syncAttendance(CourseOffering $offering): void
    {
        $meetings = DB::table('class_meetings')->where('course_offering_id', $offering->id)->where('held_at', '<=', now())->pluck('id');
        $gi = GradeItem::where('course_offering_id', $offering->id)->where('source', 'attendance')->first();
        if (! $gi || $meetings->isEmpty()) {
            return;
        }
        $present = DB::table('attendance_records')->whereIn('class_meeting_id', $meetings)->whereIn('status', ['present', 'late', 'excused'])
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) n')->pluck('n', 'user_id');
        $this->writeEntries($gi, $present->map(fn ($n) => round($n / $meetings->count() * $gi->max_score, 2))->all());
    }

    public function syncAll(CourseOffering $offering): void
    {
        foreach (CourseItem::where('course_offering_id', $offering->id)->whereIn('type', ['assignment', 'quiz'])->get() as $item) {
            $this->syncItem($item);
        }
        $this->syncExams($offering);
        $this->syncAttendance($offering);
    }

    public function setManual(GradeItem $gi, int $userId, ?float $score, User $by): void
    {
        if ($score !== null && ($score < 0 || $score > $gi->max_score)) {
            throw ValidationException::withMessages(['score' => "Marks must be between 0 and {$gi->max_score}."]);
        }
        $this->guardUnlocked($gi->course_offering_id, $userId);
        $before = DB::table('grade_entries')->where('grade_item_id', $gi->id)->where('user_id', $userId)->value('score');
        DB::table('grade_entries')->upsert([[
            'grade_item_id' => $gi->id, 'user_id' => $userId, 'score' => $score, 'overridden' => $gi->source !== 'manual',
            'updated_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
        ]], ['grade_item_id', 'user_id'], ['score', 'overridden', 'updated_by', 'updated_at']);
        $this->audit->record('gradebook.entry', $gi, ['user' => $userId, 'before' => $before, 'after' => $score], $by->id);
    }

    /** Recalculate course totals (released grades are locked and left alone). */
    public function compute(CourseOffering $offering): Collection
    {
        $this->ensureDefaults($offering);
        $categories = GradeCategory::where('course_offering_id', $offering->id)->orderBy('position')->get();
        $items = GradeItem::where('course_offering_id', $offering->id)->get()->groupBy('grade_category_id');
        $students = DB::table('enrolments')->where('course_offering_id', $offering->id)->where('status', 'active')->pluck('user_id');
        $entries = DB::table('grade_entries')->whereIn('grade_item_id', $items->flatten()->pluck('id'))->whereIn('user_id', $students)
            ->get()->groupBy('user_id')->map(fn ($g) => $g->pluck('score', 'grade_item_id'));
        $locked = DB::table('course_grades')->where('course_offering_id', $offering->id)->where('status', 'released')->pluck('user_id')->flip();
        $weights = max(0.01, $categories->sum('weight'));

        $rows = [];
        foreach ($students as $uid) {
            if (isset($locked[$uid])) {
                continue;
            }
            $breakdown = [];
            $total = 0;
            foreach ($categories as $cat) {
                $catItems = $items[$cat->id] ?? collect();
                $pcts = $catItems->map(fn ($gi) => $gi->max_score > 0 ? (float) ($entries[$uid][$gi->id] ?? 0) / $gi->max_score * 100 : 0)->values();
                $pct = match (true) {
                    $pcts->isEmpty() => null,
                    $cat->aggregation === 'sum' => ($m = $catItems->sum('max_score')) > 0 ? $catItems->sum(fn ($gi) => (float) ($entries[$uid][$gi->id] ?? 0)) / $m * 100 : 0,
                    $cat->aggregation === 'best_n' && $cat->best_n => $pcts->sortDesc()->take($cat->best_n)->avg(),
                    default => $pcts->avg(),
                };
                $breakdown[] = ['category' => $cat->name, 'weight' => $cat->weight, 'percent' => $pct === null ? null : round($pct, 2)];
                $total += ($pct ?? 0) * $cat->weight / $weights;
            }
            $rows[] = ['tenant_id' => $offering->tenant_id, 'course_offering_id' => $offering->id, 'user_id' => $uid, 'total' => round($total, 2),
                'grade' => $this->marker->grade($total), 'breakdown' => json_encode($breakdown), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('course_grades')->upsert($chunk, ['course_offering_id', 'user_id'], ['total', 'grade', 'breakdown', 'status', 'updated_at']);
        }

        return CourseGrade::where('course_offering_id', $offering->id)->get();
    }

    public function approve(CourseOffering $offering, User $by): int
    {
        $n = CourseGrade::where('course_offering_id', $offering->id)->where('status', 'draft')->update(['status' => 'approved']);
        $this->audit->record('gradebook.approved', $offering, ['count' => $n], $by->id);

        return $n;
    }

    public function release(CourseOffering $offering, User $by): int
    {
        $grades = CourseGrade::where('course_offering_id', $offering->id)->where('status', 'approved')->get();
        if ($grades->isEmpty()) {
            throw ValidationException::withMessages(['grades' => 'Nothing approved to release. Grades must be approved by the HOD or Dean first.']);
        }
        CourseGrade::whereIn('id', $grades->pluck('id'))->update(['status' => 'released', 'released_at' => now()]);
        DB::table('enrolments')->where('course_offering_id', $offering->id)->whereIn('user_id', $grades->pluck('user_id'))->where('status', 'active')->update(['status' => 'completed']);
        Notification::send(User::whereIn('id', $grades->pluck('user_id'))->get(),
            new LmsNotice('Course result released', "Your final result for {$offering->course->code} is available.", '/learn/grades', category: 'results'));
        $this->audit->record('gradebook.released', $offering, ['count' => $grades->count()], $by->id);

        return $grades->count();
    }

    private function guardUnlocked(int $offeringId, int $userId): void
    {
        if (DB::table('course_grades')->where('course_offering_id', $offeringId)->where('user_id', $userId)->where('status', 'released')->exists()) {
            throw ValidationException::withMessages(['score' => 'This result has been released and is locked. Use a result correction request.']);
        }
    }

    /** @return array<int, float|null> user id => best released score (group marks given to every member) */
    private function assignmentScores(CourseItem $item): array
    {
        $subs = AssignmentSubmission::where('course_item_id', $item->id)->where('released', true)->get(['user_id', 'course_group_id', 'score']);
        $scores = [];
        foreach ($subs as $s) {
            $members = $s->course_group_id ? DB::table('course_group_members')->where('course_group_id', $s->course_group_id)->pluck('user_id')->all() : [$s->user_id];
            foreach ($members as $uid) {
                $scores[$uid] = max($scores[$uid] ?? 0, (float) $s->score);
            }
        }

        return $scores;
    }

    private function examScores(?Exam $exam, bool $includeDraft): array
    {
        if (! $exam) {
            return [];
        }

        return DB::table('results')->where('exam_id', $exam->id)
            ->whereIn('status', $includeDraft ? ['draft', 'moderated', 'approved', 'published'] : ['approved', 'published'])
            ->pluck('final_score', 'user_id')->map(fn ($v) => $v === null ? null : (float) $v)->all();
    }

    private function writeEntries(GradeItem $gi, array $scores): void
    {
        $overridden = DB::table('grade_entries')->where('grade_item_id', $gi->id)->where('overridden', true)->pluck('user_id')->flip();
        $rows = [];
        foreach ($scores as $uid => $score) {
            if (! isset($overridden[$uid])) {
                $rows[] = ['grade_item_id' => $gi->id, 'user_id' => $uid, 'score' => $score, 'overridden' => false, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('grade_entries')->upsert($chunk, ['grade_item_id', 'user_id'], ['score', 'updated_at']);
        }
    }
}
