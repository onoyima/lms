<?php

namespace App\Services\Questions;

use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Writes questions out in the upload layout (the old exam system's text layout, with the platform's
 * extra lines), so a bank can be downloaded, edited and uploaded again, or moved to another exam.
 * Pictures are named on IMAGE: lines / {{image:…}} marks and packed beside the text in a .zip.
 */
class QuestionTextExporter
{
    /** @var array<string,string> picture name in the file => storage path */
    private array $pictures = [];

    /** @param Collection<int,Question> $questions */
    public function text(Collection $questions): string
    {
        $this->pictures = [];
        $blocks = [];
        foreach ($questions as $q) {
            $blocks[] = implode("\r\n", $this->block($q));
        }

        return implode("\r\n\r\n", $blocks)."\r\n";
    }

    /** @param Collection<int,Question> $questions */
    public function csv(Collection $questions): string
    {
        $this->pictures = [];
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF"); // Excel opens it as UTF-8
        fputcsv($fh, ['Type', 'Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Option E', 'Option F', 'Answer', 'Mark', 'Topic', 'Difficulty', 'Explanation', 'Scoring'], ',', '"', '');
        foreach ($questions as $q) {
            $q->loadMissing('options');
            $opts = $q->options->values();
            $answer = match ($q->type) {
                'multi_true_false' => $opts->map(fn ($o) => $o->is_correct ? 'T' : 'F')->implode(''),
                'true_false' => $opts->firstWhere('is_correct', true)?->body_html && strtolower(strip_tags($opts->firstWhere('is_correct', true)->body_html)) === 'false' ? 'FALSE' : 'TRUE',
                'single_choice', 'multiple_choice' => $opts->filter(fn ($o) => $o->is_correct)->keys()->map(fn ($i) => chr(65 + $i))->implode(', '),
                'numeric' => (string) ($q->numeric_answer['value'] ?? ''),
                'short_answer' => implode('; ', $q->accepted_answers['answers'] ?? []),
                'fill_blank' => collect($q->accepted_answers['blanks'] ?? [])->map(fn ($b) => implode('|', $b['answers'] ?? []))->implode('; '),
                default => '',
            };
            $optCols = [];
            for ($i = 0; $i < 6; $i++) {
                $optCols[] = $q->type === 'true_false' ? '' : (isset($opts[$i]) ? $this->plain($opts[$i]->body_html) : '');
            }
            fputcsv($fh, [self::TYPE_WORD[$q->type] ?? $q->type, $this->plain($q->type === 'fill_blank' ? self::blanksBack((string) $q->stem_html) : $q->stem_html), ...$optCols,
                $answer, (float) $q->mark, $q->topic, $q->difficulty, $q->explanation_html ? $this->plain($q->explanation_html) : '', strtoupper((string) $q->scoring)], ',', '"', '');
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    /** Pictures named by the last text() / csv() call: name in file => public storage path. */
    public function pictures(): array
    {
        return $this->pictures;
    }

    /** The text, plus a .zip with the pictures when there are any. Returns [body, filename, mime]. */
    public function download(Collection $questions, string $name, string $format = 'txt'): array
    {
        $body = $format === 'csv' ? $this->csv($questions) : $this->text($questions);
        $file = $name.'.'.$format;
        if (! $this->pictures) {
            return [$body, $file, $format === 'csv' ? 'text/csv; charset=UTF-8' : 'text/plain; charset=UTF-8'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'qz');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString($file, $body);
        foreach ($this->pictures as $pic => $path) {
            Storage::disk('public')->exists($path) && $zip->addFromString('pictures/'.$pic, Storage::disk('public')->get($path));
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return [$bytes, $name.'.zip', 'application/zip'];
    }

    private const TYPE_WORD = ['single_choice' => 'MCQ', 'multiple_choice' => 'MULTI', 'true_false' => 'TF', 'multi_true_false' => 'MTF', 'essay' => 'ESSAY',
        'short_answer' => 'SHORT', 'numeric' => 'NUMERIC', 'fill_blank' => 'BLANK', 'matching' => 'MATCHING', 'ordering' => 'ORDER', 'file_upload' => 'UPLOAD'];

    /** @return list<string> */
    private function block(Question $q): array
    {
        $q->loadMissing('options');
        $lines = ['TYPE: '.(self::TYPE_WORD[$q->type] ?? $q->type), 'MARK: '.rtrim(rtrim(number_format((float) $q->mark, 2, '.', ''), '0'), '.')];
        $q->scoring && $lines[] = 'SCORING: '.strtoupper($q->scoring);
        $stem = $q->type === 'fill_blank' ? self::blanksBack((string) $q->stem_html) : (string) $q->stem_html;
        $lines[] = $this->plain($stem) ?: '(no text)';
        $opts = $q->options->values();
        switch ($q->type) {
            case 'true_false':
                $lines[] = 'ANSWER: '.(strtolower($this->plain((string) $opts->firstWhere('is_correct', true)?->body_html)) === 'false' ? 'FALSE' : 'TRUE');
                break;
            case 'multi_true_false':
                foreach ($opts as $i => $o) {
                    $lines[] = chr(97 + $i).') '.$this->plain($o->body_html).' ['.($o->is_correct ? 'T' : 'F').']';
                }
                break;
            case 'single_choice': case 'multiple_choice': case 'ordering':
                foreach ($opts as $i => $o) {
                    $lines[] = chr(65 + $i).'. '.$this->plain($o->body_html);
                }
                $q->type !== 'ordering' && $lines[] = 'ANSWER: '.$opts->filter(fn ($o) => $o->is_correct)->keys()->map(fn ($i) => chr(65 + $i))->implode(', ');
                break;
            case 'matching':
                foreach ($q->matching['left'] ?? [] as $l) {
                    $r = collect($q->matching['right'] ?? [])->firstWhere('key', $q->matching['answer'][$l['key']] ?? null);
                    $lines[] = 'Pair: '.$this->plain($l['html']).' = '.$this->plain($r['html'] ?? '');
                }
                break;
            case 'numeric':
                $lines[] = 'ANSWER: '.($q->numeric_answer['value'] ?? '');
                ($q->numeric_answer['tolerance'] ?? 0) && $lines[] = 'Tolerance: '.$q->numeric_answer['tolerance'];
                break;
            case 'short_answer':
                $answers = $q->accepted_answers['answers'] ?? [];
                $answers && $lines[] = 'ANSWER: '.array_shift($answers);
                $answers && $lines[] = 'Accept: '.implode('; ', $answers);
                break;
            case 'fill_blank':
                $lines[] = 'ANSWER: '.collect($q->accepted_answers['blanks'] ?? [])->map(fn ($b) => implode('|', $b['answers'] ?? []))->implode('; ');
                break;
        }
        $q->topic && $lines[] = 'Topic: '.$q->topic;
        $q->difficulty && $lines[] = 'Difficulty: '.$q->difficulty;
        $q->outcome && $lines[] = 'Outcome: '.$q->outcome;
        $q->explanation_html && $lines[] = 'Explanation: '.$this->plain($q->explanation_html);

        return $lines;
    }

    /** [[1]] blanks back to ___ for the file. */
    private static function blanksBack(string $html): string
    {
        return preg_replace('/\[\[\d{1,2}\]\]/', '_____', $html);
    }

    /** HTML to one line of text: maths kept as \( \) / \[ \], pictures as {{image:name}}. */
    private function plain(?string $html): string
    {
        $html = (string) $html;
        $html = preg_replace_callback('#<img[^>]*src="([^"]+)"[^>]*>#i', function ($m) {
            $src = html_entity_decode($m[1]);
            if (! str_starts_with($src, '/storage/')) {
                return '';
            }
            $path = substr($src, strlen('/storage/'));
            $name = preg_replace('/^[A-Za-z0-9]{8}-/', '', basename($path));
            while (isset($this->pictures[$name]) && $this->pictures[$name] !== $path) {
                $name = pathinfo($name, PATHINFO_FILENAME).'-'.substr(md5($path), 0, 4).'.'.pathinfo($name, PATHINFO_EXTENSION);
            }
            $this->pictures[$name] = $path;

            return ' {{image:'.$name.'}} ';
        }, $html);
        $html = preg_replace('#</(p|div|li|tr|h\d)>|<br\s*/?>#i', ' ', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
