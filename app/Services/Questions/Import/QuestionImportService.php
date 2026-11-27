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

    /**
     * @param  string  $reader  "standard" or "science" (keeps Word equations and symbols, needs no Pandoc)
     * @param  list<UploadedFile>  $images  pictures named on IMAGE: lines or in CSV picture columns (or one .zip of them)
     * @param  array{section_id?: int, exam_id?: int, source?: string}  $options  where the questions go once saved
     */
    /** Plain messages for a question file the server refuses. */
    public static function uploadMessages(): array
    {
        $kb = (int) config('platform.question_import.max_upload_kb');

        return [
            'file.required' => 'Choose the question file first.',
            'file.uploaded' => 'The server did not accept this file. It is probably larger than the server allows (upload_max_filesize and post_max_size in php.ini). Compress the pictures in Word (File › Compress Pictures) or split the paper into two files.',
            'file.max' => 'This file is larger than '.round($kb / 1024).' MB. Compress the pictures in Word (File › Compress Pictures) or split the paper into two files.',
            'file.extensions' => 'Upload a Word file (.docx or .doc), a PDF, an Excel sheet (.xlsx), a CSV or a text file.',
            'images.*.max' => 'One of the pictures is larger than 50 MB.',
            'images.*.extensions' => 'Pictures must be PNG, JPG, GIF or WebP, or a .zip of them.',
        ];
    }

    public function upload(UploadedFile $file, ?int $courseId, int $userId, string $reader = 'standard', ?\App\Models\QuestionBank $bank = null, array $images = [], array $options = [], ?string $name = null): QuestionImport
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'txt' && CsvQuestionReader::looksLikeCsv(DocumentConverter::toUtf8((string) file_get_contents($file->getRealPath(), false, null, 0, 4096)))) {
            $ext = 'csv'; // the old system's "CSV saved as .txt" samples
        }
        // a file called .doc is often a .docx or an RTF file renamed (Word "Save as" to an old name)
        if (in_array($ext, ['doc', 'docx', 'rtf'], true)) {
            $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 8);
            $ext = str_starts_with($head, 'PK') ? 'docx' : (str_starts_with($head, '{\\rtf') ? 'rtf' : ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" ? 'doc' : $ext));
        }
        $format = $reader === 'science' && in_array($ext, ['docx', 'txt', 'md', 'html', 'htm'], true) ? 'sci-'.$ext : $ext;
        $dir = 'question-imports/'.Str::uuid();
        $stored = $file->storeAs($dir, 'original.'.$ext, 'local');

        $import = QuestionImport::create([
            'user_id' => $userId,
            'course_id' => $courseId ?? $bank?->course_id,
            'question_bank_id' => $bank?->id,
            'original_name' => mb_substr($name ?: $file->getClientOriginalName(), 0, 250),
            'stored_path' => $stored,
            'format' => $format,
            'status' => 'parsed',
        ]);
        $import->update(['options' => array_filter($options + ['images' => $this->storeImages($images, $import)])]);

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
     * AI reading: page pictures (photos, scans, or a PDF the browser turned into pictures) or a Word
     * file. Stored now; read in the background (ReadPaperWithAi); the review page waits for it.
     *
     * @param  list<UploadedFile>  $pages
     */
    public function uploadForAi(array $pages, ?UploadedFile $word, ?int $courseId, int $userId, string $name): QuestionImport
    {
        $dir = 'question-imports/'.Str::uuid();
        if ($word) {
            $stored = $word->storeAs($dir, 'original.docx', 'local');
            $format = 'ai-docx';
        } else {
            foreach (array_values($pages) as $i => $p) {
                $ext = strtolower($p->getClientOriginalExtension() ?: 'png');
                $p->storeAs($dir.'/pages', sprintf('%03d.%s', $i + 1, in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) ? $ext : 'png'), 'local');
            }
            $stored = $dir.'/pages';
            $format = 'ai-pages';
        }
        $import = QuestionImport::create([
            'user_id' => $userId, 'course_id' => $courseId, 'original_name' => mb_substr($name, 0, 250),
            'stored_path' => $stored, 'format' => $format, 'status' => 'reading',
        ]);
        $this->audit->record('question_import.ai_started', $import, ['file' => $import->original_name, 'pages' => count($pages)]);
        \App\Jobs\ReadPaperWithAi::dispatch($import->id, (int) $import->tenant_id);

        return $import;
    }

    /** AI writes new questions from a brief (topic, types, count, optional notes); reviewed like an upload. */
    public function writeWithAi(array $brief, ?int $courseId, int $userId): QuestionImport
    {
        $dir = 'question-imports/'.Str::uuid();
        Storage::disk('local')->put($dir.'/brief.json', json_encode($brief, JSON_UNESCAPED_UNICODE));
        $import = QuestionImport::create([
            'user_id' => $userId, 'course_id' => $courseId, 'original_name' => mb_substr('AI: '.($brief['topic'] ?? 'questions'), 0, 250),
            'stored_path' => $dir.'/brief.json', 'format' => 'ai-gen', 'status' => 'reading',
        ]);
        $this->audit->record('question_import.ai_writing', $import, ['topic' => $brief['topic'] ?? null, 'count' => $brief['count'] ?? null]);
        \App\Jobs\ReadPaperWithAi::dispatch($import->id, (int) $import->tenant_id);

        return $import;
    }

    /** Called by the background job: read, tidy and keep the result for the review page. */
    public function readWithAi(QuestionImport $import, \App\Services\Ai\PaperReader $reader): void
    {
        $base = dirname($import->stored_path);
        if ($import->format === 'ai-gen') {
            $read = app(\App\Services\Ai\QuestionWriter::class)->write($import, json_decode(Storage::disk('local')->get($import->stored_path), true) ?: []);
        } elseif ($import->format === 'ai-docx') {
            $mediaTmp = Storage::disk('local')->path($base.'/media');
            $converted = $this->converter->toHtml(Storage::disk('local')->path($import->stored_path), 'sci-docx', $mediaTmp);
            $html = $this->sanitizer->clean($this->publishMedia($converted['html'], $converted['media'], $mediaTmp, $import));
            $read = $reader->readHtml($import, $html);
        } else {
            $pages = collect(Storage::disk('local')->files($import->stored_path))->sort()->values()->all();
            $read = $reader->readPages($import, $pages);
        }
        $result = [
            'questions' => array_map(fn ($q) => $this->cleanParsed($q), $read['questions']),
            'warnings' => $read['warnings'],
        ];
        Storage::disk('local')->put($base.'/parsed.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $import->update(['status' => $result['questions'] ? 'parsed' : 'failed', 'question_count' => count($result['questions']),
            'warnings' => $result['warnings'] ?: ($result['questions'] ? null : ['No questions were found. Check that the pictures show the question paper clearly.'])]);
        $this->audit->record('question_import.ai_read', $import, ['questions' => count($result['questions']), 'tokens_in' => $read['usage']['input'], 'tokens_out' => $read['usage']['output']]);
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
        if (str_starts_with($import->format, 'ai-')) {
            return ['questions' => [], 'warnings' => []]; // still being read
        }

        $absolute = Storage::disk('local')->path($import->stored_path);
        $mediaTmp = Storage::disk('local')->path(dirname($import->stored_path).'/media');
        $converted = in_array($import->format, ['csv', 'xlsx'], true)
            ? ['html' => (new CsvQuestionReader)->toHtml($import->format === 'xlsx' ? (new SpreadsheetReader)->csv($absolute) : DocumentConverter::toUtf8(File::get($absolute))), 'media' => []]
            : $this->converter->toHtml($absolute, $import->format, $mediaTmp);

        // Images extracted from Word go to public storage so candidates' browsers can load them.
        $html = $this->publishMedia($converted['html'], $converted['media'], $mediaTmp, $import);
        // Text we built ourselves (text files, PDF text) keeps its line numbers and blank lines for the
        // parser; every piece is cleaned again below. Word and HTML are cleaned first.
        if (! in_array($import->format, ['txt', 'pdf', 'csv', 'xlsx', 'sci-txt', 'doc', 'rtf'], true)) {
            $html = $this->sanitizer->clean($html);
        }

        $parsed = $this->parser->parse($html);
        $images = (array) ($import->options['images'] ?? []);
        $result = [
            'questions' => array_map(fn (ParsedQuestion $q) => $this->cleanParsed($this->placeImages($q->toArray(), $images)), $parsed['questions']),
            'warnings' => $parsed['warnings'],
        ];
        Storage::disk('local')->put($cache, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    public function commit(QuestionImport $import, int $userId): int
    {
        abort_if($import->status !== 'parsed', 422, 'This import has already been saved or has failed.');
        $result = $this->parse($import);

        $bank = $import->bank;
        // Questions uploaded into an exam's own bank are checked when the paper is approved, like the
        // old system's uploads; anything else waits as a draft for the question-bank moderators.
        $status = $bank && ($bank->exam_id || $bank->exam_group_id) ? 'approved' : 'draft';
        $ids = [];

        $count = DB::transaction(function () use ($import, $result, $userId, $bank, $status, &$ids) {
            $n = 0;
            foreach ($result['questions'] as $q) {
                $question = Question::create([
                    'course_id' => $import->course_id,
                    'question_bank_id' => $bank?->id,
                    'scoring' => in_array($q['scoring'] ?? null, ['all', 'partial'], true) ? $q['scoring'] : null,
                    'question_import_id' => $import->id,
                    'created_by' => $userId,
                    'type' => in_array($q['type'], Question::TYPES, true) ? $q['type'] : 'single_choice',
                    'stem_html' => $q['stem_html'],
                    'explanation_html' => $q['explanation_html'],
                    'topic' => $q['topic'] ?? $bank?->topic,
                    'difficulty' => $q['difficulty'],
                    'mark' => $q['mark'],
                    'status' => $status,
                    'numeric_answer' => $q['type'] === 'numeric' ? ['value' => (float) str_replace(',', '.', (string) $q['answer_text']), 'tolerance' => (float) ($q['tolerance'] ?? 0)] : null,
                    'matching' => $q['type'] === 'matching' ? Question::buildMatching($q['pairs'] ?? [], fn ($h) => $this->sanitizer->clean(e($h))) : null,
                    'accepted_answers' => match (true) {
                        $q['type'] === 'short_answer' && ! empty($q['accepted']) => ['answers' => array_values(array_unique($q['accepted'])), 'case_sensitive' => false],
                        $q['type'] === 'fill_blank' => ['blanks' => array_values((array) ($q['blanks'] ?? []))],
                        $q['type'] === 'file_upload' => ['file' => ['kinds' => ['image', 'pdf', 'document'], 'max_mb' => 10]],
                        default => null,
                    },
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
                $ids[] = $question->id;
                $n++;
            }
            // uploaded from an exam's paper: the questions go straight into that section
            if ($sectionId = $import->options['section_id'] ?? null) {
                $section = \App\Models\ExamSection::find($sectionId);
                if ($section && in_array($section->exam?->status, ['draft', 'returned'], true)) {
                    $start = $section->questions()->count();
                    $section->questions()->syncWithoutDetaching(collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['position' => $start + $i]])->all());
                }
            }
            $import->update(['status' => 'committed', 'question_count' => $n]);

            return $n;
        });

        $this->audit->record('question_import.committed', $import, ['questions' => $count]);

        return $count;
    }

    /**
     * Pictures uploaded with the file (one by one, or as a .zip) go to public storage under their own
     * names, so IMAGE: lines and CSV picture columns can name them.
     *
     * @param  list<UploadedFile>  $files
     * @return array<string,string> lower-case file name => address
     */
    private function storeImages(array $files, QuestionImport $import): array
    {
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        $out = [];
        $keep = function (string $name, string $bytes) use (&$out, $allowed, $import) {
            $base = basename(str_replace('\\', '/', $name));
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (! in_array($ext, $allowed, true) || strlen($bytes) > 8 * 1024 * 1024 || ! @getimagesizefromstring($bytes) || count($out) >= 500) {
                return;
            }
            $path = 'question-media/'.$import->id.'/'.Str::random(8).'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
            Storage::disk('public')->put($path, $bytes);
            $out[strtolower($base)] = '/storage/'.$path;
        };
        foreach ($files as $f) {
            if (strtolower($f->getClientOriginalExtension()) === 'zip') {
                $zip = new \ZipArchive;
                if ($zip->open($f->getRealPath()) === true) {
                    for ($i = 0; $i < min($zip->numFiles, 1000); $i++) {
                        $entry = $zip->statIndex($i);
                        if ($entry && ! str_ends_with($entry['name'], '/') && ! str_starts_with(basename($entry['name']), '.') && $entry['size'] <= 8 * 1024 * 1024) {
                            $keep($entry['name'], (string) $zip->getFromIndex($i));
                        }
                    }
                    $zip->close();
                }
            } else {
                $keep($f->getClientOriginalName(), (string) file_get_contents($f->getRealPath()));
            }
        }

        return $out;
    }

    /** IMAGE: lines and {{image:name}} marks become pictures; a name with no uploaded picture is reported. */
    private function placeImages(array $q, array $images): array
    {
        $missing = [];
        $tag = function (string $name) use ($images, &$missing) {
            $url = $images[strtolower(basename(trim($name)))] ?? null;
            if (! $url) {
                $missing[] = trim($name);

                return '';
            }

            return '<img src="'.e($url).'" alt="">';
        };
        $replace = fn (string $html) => preg_replace_callback('/\{\{image:([^}]{1,190})\}\}/', fn ($m) => $tag(html_entity_decode($m[1])), $html);
        if (! empty($q['image'])) {
            $img = $tag($q['image']);
            $img !== '' && $q['stem_html'] = '<p>'.$img.'</p>'.$q['stem_html'];
        }
        $q['stem_html'] = $replace($q['stem_html']);
        foreach ($q['options'] as &$o) {
            $o['html'] = $replace($o['html']);
        }
        unset($o);
        foreach (array_unique($missing) as $name) {
            $q['warnings'][] = "The picture \"$name\" was not among the pictures uploaded with this file.";
        }

        return $q;
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
