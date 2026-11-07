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
            'recent' => QuestionImport::latest('id')->limit(10)->get(['id', 'original_name', 'status', 'question_count', 'created_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cfg = config('platform.question_import');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$cfg['max_upload_kb'], 'extensions:'.implode(',', $cfg['allowed_extensions'])],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
            'reader' => ['nullable', 'in:standard,science'],
        ]);

        $import = $this->imports->upload($data['file'], $data['course_id'] ?? null, $request->user()->id, $data['reader'] ?? 'standard');

        return redirect()->route('question-bank.imports.show', $import);
    }

    public function show(QuestionImport $import): Response
    {
        $result = $import->status === 'failed' ? ['questions' => [], 'warnings' => []] : $this->imports->parse($import);

        return Inertia::render('question-bank/import-preview', [
            'import' => $import->only(['id', 'original_name', 'status', 'question_count', 'warnings', 'format']),
            'questions' => $result['questions'],
            'documentWarnings' => $result['warnings'],
        ]);
    }

    public function commit(Request $request, QuestionImport $import): RedirectResponse
    {
        $count = $this->imports->commit($import, $request->user()->id);

        return redirect()->route('question-bank.index')->with('success', "$count questions added to the question bank as drafts.");
    }
}
