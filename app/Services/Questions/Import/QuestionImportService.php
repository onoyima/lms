<?php

namespace App\Services\Questions\Import;

use App\Models\Question;
use App\Models\QuestionImport;
use App\Services\Audit\AuditLogger;
use App\Services\Questions\RichTextSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload -> convert -> sanitise -> parse -> preview -> commit.
 * Nothing is written to the question bank until the setter confirms the preview.
 */
class QuestionImportService
{
    public function __construct(
        private DocumentConverter $converter,
        private QuestionParser $parser,
        private RichTextSanitizer $sanitizer,
        private AuditLogger $audit,
    ) {}

    /** @param  string  $reader  "standard" or "science" (keeps Word equations and symbols, needs no Pandoc) */
    public function upload(UploadedFile $file, ?int $courseId, int $userId, string $reader = 'standard'): QuestionImport
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $format = $reader === 'science' ? 'sci-'.$ext : $ext;
        $dir = 'question-imports/'.Str::uuid();
        $stored = $file->storeAs($dir, 'original.'.$ext, 'local');

        $import = QuestionImport::create([
            'user_id' => $userId,
            'course_id' => $courseId,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'stored_path' => $stored,
            'format' => $format,
            'status' => 'parsed',
        ]);

        try {
            $result = $this->parse($import);
            $import->update(['question_count' => count($result['questions']), 'warnings' => $result['warnings'] ?: null]);
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed', 'warnings' => [$e->getMessage()]]);
        }

        $this->audit->record('question_import.uploaded', $import, ['file' => $import->original_name, 'status' => $import->status]);

        return $import;
    }

    /**
     * @return array{questions: list<array>, warnings: list<string>}
     */
    public function parse(QuestionImport $import): array
    {
        $cache = dirname($import->stored_path).'/parsed.json';
        if (Storage::disk('local')->exists($cache)) {
            return json_decode(Storage::disk('local')->get($cache), true);
        }

        $absolute = Storage::disk('local')->path($import->stored_path);
        $mediaTmp = Storage::disk('local')->path(dirname($import->stored_path).'/media');
        $converted = $this->converter->toHtml($absolute, $import->format, $mediaTmp);

        // Images extracted from Word go to public storage so candidates' browsers can load them.
        $html = $this->publishMedia($converted['html'], $converted['media'], $mediaTmp, $import);
        $html = $this->sanitizer->clean($html);

        $parsed = $this->parser->parse($html);
        $result = [
            'questions' => array_map(fn (ParsedQuestion $q) => $this->cleanParsed($q->toArray()), $parsed['questions']),
            'warnings' => $parsed['warnings'],
        ];
        Storage::disk('local')->put($cache, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    public function commit(QuestionImport $import, int $userId): int
    {
        abort_if($import->status !== 'parsed', 422, 'This import has already been saved or has failed.');
        $result = $this->parse($import);

        $count = DB::transaction(function () use ($import, $result, $userId) {
            $n = 0;
            foreach ($result['questions'] as $q) {
                $question = Question::create([
                    'course_id' => $import->course_id,
                    'question_import_id' => $import->id,
                    'created_by' => $userId,
                    'type' => in_array($q['type'], Question::TYPES, true) ? $q['type'] : 'single_choice',
                    'stem_html' => $q['stem_html'],
                    'explanation_html' => $q['explanation_html'],
                    'topic' => $q['topic'],
                    'difficulty' => $q['difficulty'],
                    'mark' => $q['mark'],
                    'status' => 'draft',
                    'numeric_answer' => $q['type'] === 'numeric' ? ['value' => (float) str_replace(',', '.', (string) $q['answer_text']), 'tolerance' => (float) ($q['tolerance'] ?? 0)] : null,
                    'matching' => $q['type'] === 'matching' ? Question::buildMatching($q['pairs'] ?? [], fn ($h) => $this->sanitizer->clean(e($h))) : null,
                    'accepted_answers' => $q['type'] === 'short_answer' && ! empty($q['accepted']) ? ['answers' => array_values(array_unique($q['accepted'])), 'case_sensitive' => false] : null,
                    'outcome' => $q['outcome'] ?? null,
                ]);
                foreach ($q['options'] as $i => $o) {
                    $question->options()->create([
                        'label' => $o['label'],
                        'body_html' => $o['html'],
                        'is_correct' => $o['correct'],
                        'position' => $i,
                    ]);
                }
                $n++;
            }
            $import->update(['status' => 'committed', 'question_count' => $n]);

            return $n;
        });

        $this->audit->record('question_import.committed', $import, ['questions' => $count]);

        return $count;
    }

    private function cleanParsed(array $q): array
    {
        $q['stem_html'] = $this->sanitizer->clean($q['stem_html']);
        $q['explanation_html'] = $q['explanation_html'] ? $this->sanitizer->clean($q['explanation_html']) : null;
        foreach ($q['options'] as &$o) {
            $o['html'] = $this->sanitizer->clean($o['html']);
        }

        return $q;
    }

    /** @param list<string> $media */
    private function publishMedia(string $html, array $media, string $mediaTmp, QuestionImport $import): string
    {
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        foreach ($media as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $relative = 'question-media/'.$import->id.'/'.Str::random(12).'.'.$ext;
            if (in_array($ext, $allowed, true) && @getimagesize($path)) {
                Storage::disk('public')->put($relative, File::get($path));
                $html = str_replace([$path, str_replace('\\', '/', $path)], '/storage/'.$relative, $html);
            }
        }
        // Word "EMF/WMF" pictures cannot be shown in browsers; drop them with a note.
        $html = preg_replace('#<img[^>]+src="(?!/storage/)[^"]*"[^>]*>#i', '<em>[image could not be converted — please re-insert as PNG/JPG]</em>', $html);
        File::deleteDirectory($mediaTmp);

        return $html;
    }
}
