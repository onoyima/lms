<?php

namespace App\Http\Controllers\QuestionBank;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\QuestionImport;
use App\Services\Questions\Import\DocumentConverter;
use App\Services\Questions\Import\QuestionImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function __construct(private QuestionImportService $imports) {}

    public function create(DocumentConverter $converter): Response
    {
        return Inertia::render('question-bank/import', [
            'courses' => Course::orderBy('code')->get(['id', 'code', 'title']),
            'pandoc' => $converter->pandocAvailable(),
            'ai' => \App\Services\Ai\AiSettings::enabled('read_papers'),
            'aiWrite' => \App\Services\Ai\AiSettings::enabled('write_questions'),
            'recent' => QuestionImport::latest('id')->limit(10)->get(['id', 'original_name', 'status', 'question_count', 'created_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->input('reader') === 'ai') {
            return $this->storeForAi($request);
        }
        $cfg = config('platform.question_import');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$cfg['max_upload_kb'], 'extensions:'.implode(',', $cfg['allowed_extensions'])],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
            'reader' => ['nullable', 'in:standard,science'],
            'bank_id' => ['nullable', 'integer', Rule::exists('question_banks', 'id')->where('tenant_id', tenant_id())],
            'section_id' => ['nullable', 'integer', Rule::exists('exam_sections', 'id')],
            'images' => ['nullable', 'array', 'max:300'],
            'images.*' => ['file', 'max:51200', 'extensions:zip,png,jpg,jpeg,gif,webp'],
            'name' => ['nullable', 'string', 'max:250'], // a PDF's name when the browser sends its text
        ]);
        $bank = isset($data['bank_id']) ? \App\Models\QuestionBank::find($data['bank_id']) : null;
        $section = isset($data['section_id']) ? \App\Models\ExamSection::with('exam')->find($data['section_id']) : null;
        abort_if($section && (! $section->exam || ($bank && $bank->exam_id && $bank->exam_id !== $section->exam_id)), 422, 'That section is not part of this exam.');
        abort_if($section && ! $request->user()->can('exams.manage'), 403);
        // uploaded straight onto a paper: the questions also go into that paper's own bank
        if ($section && ! $bank) {
            $bank = \App\Models\QuestionBank::firstOrCreate(['exam_id' => $section->exam_id, 'name' => 'Uploaded questions'], ['course_id' => $section->exam->course_id, 'created_by' => $request->user()->id]);
        }

        // without "write questions", uploads go only into an exam's own banks
        abort_if(! $request->user()->can('questions.create') && ! ($bank && ($bank->exam_id || $bank->exam_group_id)), 403);
        $import = $this->imports->upload($data['file'], $data['course_id'] ?? $section?->exam?->course_id, $request->user()->id, $data['reader'] ?? 'standard',
            $bank, $data['images'] ?? [], array_filter(['section_id' => $section?->id, 'exam_id' => $section?->exam_id ?? $bank?->exam_id]), $data['name'] ?? null);

        return redirect()->route('question-bank.imports.show', $import);
    }

    /**
     * AI reading: pictures of the pages (photos, scans, or a PDF the browser has turned into page
     * pictures), or a Word file. Read in the background; the review page shows progress.
     */
    private function storeForAi(Request $request): RedirectResponse
    {
        abort_unless(\App\Services\Ai\AiSettings::enabled('read_papers'), 422, 'AI reading is not set up. An administrator can add it under Settings › AI.');
        $data = $request->validate([
            'pages' => ['required_without:file', 'array', 'max:60'],
            'pages.*' => ['file', 'mimes:png,jpg,jpeg,webp', 'max:15360'],
            'file' => ['required_without:pages', 'nullable', 'file', 'max:'.config('platform.question_import.max_upload_kb'), 'extensions:docx'],
            'name' => ['nullable', 'string', 'max:250'],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
        ]);
        $import = $this->imports->uploadForAi($data['pages'] ?? [], $data['file'] ?? null, $data['course_id'] ?? null, $request->user()->id,
            $data['name'] ?? ($data['file'] ?? null)?->getClientOriginalName() ?? 'Question paper');

        return redirect()->route('question-bank.imports.show', $import);
    }

    /** AI drafts new questions from a topic (and the lecturer's notes, if given), for review. */
    public function write(Request $request): RedirectResponse
    {
        abort_unless(\App\Services\Ai\AiSettings::enabled('write_questions'), 422, 'AI question writing is not switched on.');
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:300'],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
            'types' => ['required', 'array', 'min:1'], 'types.*' => [Rule::in(\App\Models\Question::TYPES)],
            'count' => ['required', 'integer', 'min:1', 'max:40'],
            'difficulty' => ['nullable', 'in:easy,medium,hard,mixed'],
            'level' => ['nullable', 'string', 'max:60'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:60000'],
        ]);
        $course = isset($data['course_id']) ? \App\Models\Course::find($data['course_id']) : null;
        $import = $this->imports->writeWithAi($data + ['course' => $course ? $course->code.' — '.$course->title : null], $data['course_id'] ?? null, $request->user()->id);

        return redirect()->route('question-bank.imports.show', $import);
    }

    /** Read a failed AI import again (the pictures are kept). */
    public function retry(QuestionImport $import): RedirectResponse
    {
        abort_unless(str_starts_with($import->format, 'ai-') && $import->status === 'failed', 422);
        $import->update(['status' => 'reading', 'warnings' => null]);
        \App\Jobs\ReadPaperWithAi::dispatch($import->id, (int) $import->tenant_id);

        return back();
    }

    public function show(QuestionImport $import): Response
    {
        $result = in_array($import->status, ['failed', 'reading'], true) ? ['questions' => [], 'warnings' => []] : $this->imports->parse($import);

        $bank = $import->bank;
        $examId = $import->options['exam_id'] ?? $bank?->exam_id;

        return Inertia::render('question-bank/import-preview', [
            'import' => $import->only(['id', 'original_name', 'status', 'question_count', 'warnings', 'format']),
            // where the questions go when saved
            'destination' => ['bank' => $bank?->name, 'exam' => $examId ? \App\Models\Exam::find($examId)?->only(['id', 'title']) : null,
                'section' => isset($import->options['section_id']) ? \App\Models\ExamSection::find($import->options['section_id'])?->title : null,
                'pictures' => count((array) ($import->options['images'] ?? []))],
            'questions' => $result['questions'],
            'documentWarnings' => $result['warnings'],
            // the original page pictures of an AI-read paper, to compare with what was read
            'pages' => $import->format === 'ai-pages' ? count(\Illuminate\Support\Facades\Storage::disk('local')->files($import->stored_path)) : 0,
        ]);
    }

    public function page(QuestionImport $import, int $n): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($import->format === 'ai-pages', 404);
        $file = collect(\Illuminate\Support\Facades\Storage::disk('local')->files($import->stored_path))->sort()->values()->get($n - 1);
        abort_unless($file, 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($file, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function commit(Request $request, QuestionImport $import): RedirectResponse
    {
        $count = $this->imports->commit($import, $request->user()->id);
        $examId = $import->options['exam_id'] ?? $import->bank?->exam_id;
        $where = $import->bank ? "the bank \"{$import->bank->name}\"" : 'the question bank as drafts';
        if (isset($import->options['section_id'])) {
            $where .= ' and to the paper';
        }
        if ($examId || $import->bank?->exam_group_id) {
            return redirect($examId ? "/exams/{$examId}?tab=banks" : "/exam-groups/{$import->bank->exam_group_id}")->with('success', "$count questions added to $where.");
        }

        return redirect()->route('question-bank.index', $import->bank ? ['bank_id' => $import->bank->id] : [])->with('success', "$count questions added to $where.");
    }
}
