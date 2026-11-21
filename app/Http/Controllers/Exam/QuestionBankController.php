<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamGroup;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Services\Audit\AuditLogger;
use App\Services\Questions\QuestionTextExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * An exam's own question banks (one per topic, if wanted): make, rename, empty, delete, download in
 * the upload layout, and put a whole bank into a section of the paper. Uploading into a bank goes
 * through the normal import preview (Question bank › Import) with the bank chosen.
 */
class QuestionBankController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'topic' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'exam_id' => ['nullable', 'required_without_all:exam_group_id,course_id', 'integer', Rule::exists('exams', 'id')->where('tenant_id', tenant_id())],
            'exam_group_id' => ['nullable', 'integer', Rule::exists('exam_groups', 'id')->where('tenant_id', tenant_id())],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
            'scope' => ['nullable', 'in:exam,group,course'],
        ]);
        $exam = isset($data['exam_id']) ? Exam::findOrFail($data['exam_id']) : null;
        // "for this paper", "for every paper in the exam category" or "for the course" — chosen from the exam page
        $owner = match ($data['scope'] ?? null) {
            'group' => ['exam_group_id' => $exam?->exam_group_id ?? $data['exam_group_id'] ?? null],
            'course' => ['course_id' => $exam?->course_id ?? $data['course_id'] ?? null],
            default => $exam ? ['exam_id' => $exam->id, 'course_id' => $exam->course_id] : array_filter(['exam_group_id' => $data['exam_group_id'] ?? null, 'course_id' => $data['course_id'] ?? null]),
        };
        abort_if(! array_filter($owner), 422, 'Choose where the bank belongs.');
        $bank = QuestionBank::create(['name' => $data['name'], 'topic' => $data['topic'] ?? null, 'description' => $data['description'] ?? null, 'created_by' => $request->user()->id] + $owner);
        $this->audit->record('question_bank.created', $bank, ['name' => $bank->name]);

        return back()->with('success', "Bank \"{$bank->name}\" made. Upload questions into it, or add them one by one.");
    }

    public function update(Request $request, QuestionBank $bank): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'topic' => ['nullable', 'string', 'max:190'], 'description' => ['nullable', 'string', 'max:2000']]);
        $bank->update($data);

        return back()->with('success', 'Bank saved.');
    }

    /** Remove every question from the bank (the old system's "Clear all"). Papers already taken keep their copies. */
    public function clear(QuestionBank $bank): RedirectResponse
    {
        $this->assertEditable($bank);
        $n = DB::transaction(function () use ($bank) {
            $ids = $bank->questions()->pluck('id');
            DB::table('exam_section_questions')->whereIn('question_id', $ids)
                ->whereIn('exam_section_id', ExamSection::whereHas('exam', fn ($e) => $e->whereIn('status', ['draft', 'returned']))->select('id'))->delete();

            return Question::whereIn('id', $ids)->delete(); // soft delete: attempts keep their question versions
        });
        $this->audit->record('question_bank.cleared', $bank, ['questions' => $n]);

        return back()->with('success', "$n questions removed from \"{$bank->name}\".");
    }

    public function destroy(Request $request, QuestionBank $bank): RedirectResponse
    {
        $count = $bank->questions()->count();
        if ($count && ! $request->boolean('with_questions')) {
            return back()->with('error', "\"{$bank->name}\" still has $count questions. Empty it first, or delete it with its questions.");
        }
        $count && $this->clear($bank);
        $this->audit->record('question_bank.deleted', $bank, ['name' => $bank->name]);
        $bank->delete();

        return back()->with('success', 'Bank deleted.');
    }

    /** The bank's questions in the upload layout (text or CSV), with its pictures in a .zip when it has any. */
    public function export(Request $request, QuestionBank $bank, string $format, QuestionTextExporter $exporter)
    {
        $questions = $bank->questions()->with('options')->orderBy('id')->get();
        [$body, $file, $mime] = $exporter->download($questions, Str::slug($bank->name) ?: 'questions', $format);
        $this->audit->record('question_bank.exported', $bank, ['count' => $questions->count(), 'format' => $format]);

        return response($body, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.$file.'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Every question on an exam's paper and in its own banks, in the upload layout. */
    public function exportExam(Exam $exam, string $format, QuestionTextExporter $exporter)
    {
        $exam->load('sections.questions.options');
        $ids = $exam->sections->flatMap(fn ($s) => $s->questions->pluck('id'))
            ->merge(Question::whereIn('question_bank_id', QuestionBank::where('exam_id', $exam->id)->select('id'))->pluck('id'))->unique();
        $questions = Question::with('options')->whereIn('id', $ids)->orderBy('id')->get();
        [$body, $file, $mime] = $exporter->download($questions, Str::slug($exam->title) ?: 'exam-questions', $format);
        $this->audit->record('exam.questions_exported', $exam, ['count' => $questions->count(), 'format' => $format]);

        return response($body, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.$file.'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** For the picker: the bank's questions, with what a setter needs to choose. */
    public function questions(Request $request, QuestionBank $bank): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        return response()->json($bank->questions()->with('options')
            ->when($q !== '', fn ($w) => $w->where('stem_html', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('id')->limit(1000)->get()
            ->map(fn ($x) => ['id' => $x->id, 'type' => $x->type, 'stem_html' => $x->stem_html, 'mark' => (float) $x->mark, 'topic' => $x->topic, 'difficulty' => $x->difficulty, 'status' => $x->status, 'bank_id' => $bank->id]));
    }

    /** Put every question of one or more banks on the paper as fixed questions. */
    public function attachAll(Request $request, Exam $exam, ExamSection $section): RedirectResponse
    {
        abort_unless(in_array($exam->status, ['draft', 'returned'], true), 422, 'This exam is approved or live. Reopen it for editing first.');
        abort_unless($section->exam_id === $exam->id, 404);
        $data = $request->validate(['bank_ids' => ['required', 'array', 'min:1', 'max:50'], 'bank_ids.*' => ['integer']]);
        $banks = QuestionBank::forExam($exam)->whereIn('id', $data['bank_ids'])->pluck('id');
        abort_if($banks->count() !== count(array_unique($data['bank_ids'])), 422, 'A chosen bank does not belong to this exam.');
        $ids = Question::whereIn('question_bank_id', $banks)->whereIn('status', ['approved', 'draft', 'in_review'])->orderBy('id')->pluck('id');
        $start = $section->questions()->count();
        $section->questions()->syncWithoutDetaching($ids->values()->mapWithKeys(fn ($id, $i) => [$id => ['position' => $start + $i]])->all());

        return back()->with('success', $ids->count().' questions on the paper.');
    }

    /** Take every fixed question off the paper (the banks keep them). */
    public function detachAll(Exam $exam): RedirectResponse
    {
        abort_unless(in_array($exam->status, ['draft', 'returned'], true), 422, 'This exam is approved or live. Reopen it for editing first.');
        $n = DB::table('exam_section_questions')->whereIn('exam_section_id', $exam->sections()->select('id'))->delete();
        $this->audit->record('exam.questions_cleared', $exam, ['questions' => $n]);

        return back()->with('success', "$n questions taken off the paper. They are still in their banks.");
    }

    private function assertEditable(QuestionBank $bank): void
    {
        if ($bank->exam_id) {
            abort_unless(in_array($bank->exam?->status, ['draft', 'returned'], true), 422, 'This exam is approved or live. Reopen it for editing first.');
        }
        if ($bank->exam_group_id) {
            abort_if(Exam::where('exam_group_id', $bank->exam_group_id)->whereIn('status', ['live'])->exists(), 422, 'A paper in this exam is being written now. Empty the bank after it closes.');
        }
    }

    /** Banks of an exam category (all its papers), for the category page. */
    public static function forGroup(ExamGroup $group): array
    {
        return QuestionBank::where('exam_group_id', $group->id)->withCount('questions')->orderBy('name')->get()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'topic' => $b->topic, 'questions' => $b->questions_count])->all();
    }
}
