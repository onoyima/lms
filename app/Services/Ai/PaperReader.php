<?php

namespace App\Services\Ai;

use App\Models\Question;
use App\Models\QuestionImport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads a question paper with AI: photos or scans of pages (a PDF is turned into page pictures in the
 * setter's browser), or a Word file. Every question comes back with its type, text (maths in LaTeX,
 * chemistry in \ce{}), options, answer key when the paper shows one, marks, and the position of any
 * diagram, which is cut out of the page picture and placed with the question. The setter then checks
 * everything on the review page, exactly as students will see it, before anything is saved.
 */
class PaperReader
{
    /** Pages sent in one request; the last page of a batch is sent again with the next one, so a question across two pages is read whole. */
    public const PAGES_PER_REQUEST = 5;

    public function __construct(private ?AiClient $client = null) {}

    public static function instructions(): string
    {
        $types = implode(', ', Question::TYPES);

        return <<<TXT
You read examination question papers for a university and turn them into structured questions.
Copy the wording exactly as printed. Never invent a question, an option or an answer.

Write mathematics in LaTeX inside \\( \\) for inline and \\[ \\] for display (fractions, roots, powers, integrals, vectors, matrices, units such as \\(\\mathrm{m\\,s^{-2}}\\)).
Write chemistry with mhchem inside \\( \\): \\(\\ce{H2SO4}\\), \\(\\ce{2H2 + O2 -> 2H2O}\\), \\(\\ce{Fe^{3+}}\\), equilibria \\(\\ce{<=>}\\).
Physics symbols, Greek letters and special signs go in LaTeX too (\\(\\Omega\\), \\(\\mu\\), \\(\\Delta H\\), \\(\\pm\\), \\(\\approx\\)).
Tables become HTML <table> with <tr>, <th>, <td>. Keep sub-parts (a), (b), (i), (ii) inside the question text as paragraphs.
Use simple HTML only: <p>, <br>, <strong>, <em>, <sub>, <sup>, <ul>, <ol>, <li>, <table>.

Question types: {$types}.
- single_choice: lettered options, one correct. multiple_choice: more than one correct ("choose all that apply").
- true_false: options are True and False. multi_true_false: several statements each to be marked true or false (set "correct": true for a true statement).
- matching: two lists to pair up (give "pairs" as left/right in the correct pairing).
- numeric: a number answer (give "answer" and "tolerance" if stated). short_answer: one word or phrase (give accepted answers in "accepted").
- ordering: items to put in order (give "options" in the CORRECT order). fill_blank: put [[1]], [[2]]… in the stem where each gap is, and give "blanks": [{"answers": ["…", "…"]}] for each gap in order.
- file_upload: the candidate must hand in a drawing, a report or a file.
- essay: theory, explain, describe, discuss, calculate-with-working, and any question answered in writing.
If the paper marks the answer (an answer key, a tick, a circled or bold option, "Ans:"), set it. If it does not, leave every "correct" false and add a note "answer not shown on the paper".

Diagrams, graphs, circuits, structures, photos and drawn figures: give their position on the page picture as a box of fractions [left, top, right, bottom] from 0 to 1 (pages are numbered from 1 in the order given), and say whether the figure belongs to the question ("for": "stem") or to an option ("for": "A", "B"…). Include a little white space around the figure. In a Word paper, figures are already marked [[IMG n]]: refer to them as {"image": n, "for": …} instead of a box.

Reply with JSON only, no other words:
{"questions":[{"number":1,"type":"single_choice","stem":"<p>…</p>","options":[{"label":"A","html":"…","correct":false}],"answer":null,"tolerance":null,"accepted":[],"pairs":[],"mark":null,"topic":null,"explanation":null,"figures":[{"page":1,"box":[0.1,0.3,0.6,0.55],"for":"stem"}],"confidence":"high","notes":null}],"cut_off":false}
"confidence" is high, medium or low (low when the print is unclear or you are unsure of a symbol); explain in "notes".
TXT;
    }

    /**
     * @param  list<string>  $pages  stored paths (local disk) of page pictures, in order
     * @return array{questions: list<array>, warnings: list<string>, usage: array{input: int, output: int}}
     */
    public function readPages(QuestionImport $import, array $pages): array
    {
        $client = $this->client ?? AiClient::forTenant();
        $questions = [];
        $warnings = [];
        $usage = ['input' => 0, 'output' => 0];
        $last = 0;
        $step = max(1, self::PAGES_PER_REQUEST - 1);
        for ($from = 0; $from < count($pages); $from += $step) {
            $batch = array_slice($pages, $from, self::PAGES_PER_REQUEST, true);
            $parts = [['text' => 'Pages '.($from + 1).' to '.($from + count($batch)).' of '.count($pages).'.'
                .($last ? " Questions up to number $last have already been read: return only questions after it (the first page here was in the last batch too)." : '')
                .($from + count($batch) < count($pages) ? ' If the last question is cut off at the end of the last page, leave it out; it is read with the next pages.' : '')]];
            foreach (array_values($batch) as $i => $path) {
                $parts[] = ['text' => 'Page '.($from + $i + 1).':'];
                $parts[] = ['image' => Storage::disk('local')->get($path), 'mime' => $this->mime($path)];
            }
            $reply = $client->ask(self::instructions(), $parts, 16000);
            $usage['input'] += (int) $reply['input_tokens'];
            $usage['output'] += (int) $reply['output_tokens'];
            $json = AiClient::json($reply['text']);
            if (! is_array($json) || ! isset($json['questions']) || ! is_array($json['questions'])) {
                $warnings[] = 'Pages '.($from + 1).'–'.($from + count($batch)).' could not be read (the AI did not answer in the expected form). Try again, or upload clearer pictures of those pages.';

                continue;
            }
            foreach ($json['questions'] as $q) {
                if (! is_array($q) || ((int) ($q['number'] ?? 0) <= $last && $last > 0)) {
                    continue;
                }
                $questions[] = $this->shape($import, $q, $pages, null);
            }
            $last = max($last, ...array_map(fn ($q) => (int) $q['number'], $questions ?: [['number' => 0]]));
            if ($from + self::PAGES_PER_REQUEST >= count($pages)) {
                break;
            }
        }

        return ['questions' => $questions, 'warnings' => $warnings, 'usage' => $usage];
    }

    /**
     * A Word file already converted to HTML (equations as LaTeX, pictures published): the AI only sorts
     * it into questions. Pictures are given to it as [[IMG n]] markers.
     *
     * @return array{questions: list<array>, warnings: list<string>, usage: array{input: int, output: int}}
     */
    public function readHtml(QuestionImport $import, string $html): array
    {
        $client = $this->client ?? AiClient::forTenant();
        $images = [];
        $marked = preg_replace_callback('#<img[^>]+src="([^"]+)"[^>]*>#i', function ($m) use (&$images) {
            $images[] = $m[1];

            return '[[IMG '.count($images).']]';
        }, $html);
        $reply = $client->ask(self::instructions(), [['text' => "The paper, converted from Word (equations already in LaTeX):\n\n".mb_substr($marked, 0, 400000)]], 16000);
        $json = AiClient::json($reply['text']);
        if (! is_array($json) || ! isset($json['questions'])) {
            throw new RuntimeException('The AI did not answer in the expected form. Try again.');
        }

        return [
            'questions' => array_map(fn ($q) => $this->shape($import, (array) $q, [], $images), array_values(array_filter($json['questions'], 'is_array'))),
            'warnings' => [],
            'usage' => ['input' => (int) $reply['input_tokens'], 'output' => (int) $reply['output_tokens']],
        ];
    }

    /** One question without page pictures (used when AI writes questions). */
    public function shapeQuestion(QuestionImport $import, array $q): array
    {
        return $this->shape($import, $q, [], []);
    }

    /** One AI question → the shape the review page and the question bank use. */
    private function shape(QuestionImport $import, array $q, array $pages, ?array $images): array
    {
        $type = in_array($q['type'] ?? '', Question::TYPES, true) ? $q['type'] : (empty($q['options']) ? 'essay' : 'single_choice');
        $options = [];
        foreach (array_values((array) ($q['options'] ?? [])) as $i => $o) {
            $options[] = ['label' => strtoupper(substr((string) ($o['label'] ?? chr(65 + $i)), 0, 2)) ?: chr(65 + $i), 'html' => (string) ($o['html'] ?? $o['text'] ?? ''), 'correct' => (bool) ($o['correct'] ?? false)];
        }
        $stem = (string) ($q['stem'] ?? '');
        $warnings = [];

        foreach ((array) ($q['figures'] ?? []) as $f) {
            $img = $images !== null ? $this->wordImage($images, $f) : $this->crop($import, $pages, (array) $f);
            if (! $img) {
                $warnings[] = 'A diagram could not be cut out'.(isset($f['page']) ? ' (page '.(int) $f['page'].')' : '').': add it after saving.';

                continue;
            }
            $tag = '<figure><img src="'.e($img).'" alt="Diagram for question '.(int) ($q['number'] ?? 0).'"></figure>';
            $for = strtoupper((string) ($f['for'] ?? 'stem'));
            $hit = false;
            foreach ($options as &$o) {
                if ($o['label'] === $for) {
                    $o['html'] .= $tag;
                    $hit = true;
                }
            }
            unset($o);
            if (! $hit) {
                $stem .= $tag;
            }
        }

        if ($type === 'ordering') {
            foreach ($options as &$o) {
                $o['correct'] = true;
            }
            unset($o);
        }
        $choice = in_array($type, ['single_choice', 'multiple_choice', 'true_false'], true);
        if ($choice && ! array_filter($options, fn ($o) => $o['correct'])) {
            $warnings[] = 'No answer shown on the paper: mark the correct option after saving.';
        }
        if ($choice && count(array_filter($options, fn ($o) => $o['correct'])) > 1 && $type !== 'multiple_choice') {
            $type = 'multiple_choice';
        }
        if (($q['confidence'] ?? 'high') === 'low') {
            $warnings[] = 'The AI was unsure about this question'.(! empty($q['notes']) ? ': '.mb_substr((string) $q['notes'], 0, 200) : '.').' Check it against the paper.';
        } elseif (! empty($q['notes'])) {
            $warnings[] = 'Note from the AI: '.mb_substr((string) $q['notes'], 0, 200);
        }

        return [
            'number' => (int) ($q['number'] ?? 0),
            'type' => $type,
            'stem_html' => $stem,
            'options' => $type === 'matching' ? [] : $options,
            'explanation_html' => ! empty($q['explanation']) ? (string) $q['explanation'] : null,
            'answer_text' => isset($q['answer']) && $q['answer'] !== '' ? (string) $q['answer'] : null,
            'mark' => isset($q['mark']) && is_numeric($q['mark']) && $q['mark'] > 0 ? (float) $q['mark'] : 1.0,
            'topic' => isset($q['topic']) ? mb_substr((string) $q['topic'], 0, 190) : null,
            'difficulty' => null,
            'outcome' => null,
            'tolerance' => isset($q['tolerance']) && is_numeric($q['tolerance']) ? (float) $q['tolerance'] : null,
            'pairs' => array_values(array_filter(array_map(fn ($p) => ['left' => (string) ($p['left'] ?? ''), 'right' => (string) ($p['right'] ?? '')], (array) ($q['pairs'] ?? [])), fn ($p) => $p['left'] !== '' && $p['right'] !== '')),
            'accepted' => array_values(array_filter(array_map('strval', (array) ($q['accepted'] ?? [])))),
            'blanks' => $type === 'fill_blank' ? array_values(array_map(fn ($b) => ['answers' => array_values(array_filter(array_map('strval', (array) ($b['answers'] ?? []))))], array_filter((array) ($q['blanks'] ?? []), 'is_array'))) : [],
            'warnings' => $warnings,
        ];
    }

    private function wordImage(array $images, mixed $f): ?string
    {
        $n = (int) (is_array($f) ? ($f['image'] ?? 0) : 0);

        return $images[$n - 1] ?? null;
    }

    /** Cut a diagram out of a page picture (box in fractions of the page) and publish it. */
    private function crop(QuestionImport $import, array $pages, array $f): ?string
    {
        $page = (int) ($f['page'] ?? 0);
        $box = array_map('floatval', array_values((array) ($f['box'] ?? [])));
        $path = array_values($pages)[$page - 1] ?? null;
        if (! $path || count($box) !== 4 || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        [$l, $t, $r, $b] = $box;
        $l = max(0, min(1, $l));
        $t = max(0, min(1, $t));
        $r = max(0, min(1, $r));
        $b = max(0, min(1, $b));
        if ($r - $l < 0.02 || $b - $t < 0.02) {
            return null;
        }
        $src = @imagecreatefromstring(Storage::disk('local')->get($path));
        if (! $src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $part = imagecrop($src, ['x' => (int) floor($l * $w), 'y' => (int) floor($t * $h), 'width' => max(1, (int) round(($r - $l) * $w)), 'height' => max(1, (int) round(($b - $t) * $h))]);
        imagedestroy($src);
        if (! $part) {
            return null;
        }
        ob_start();
        imagepng($part, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($part);
        $relative = 'question-media/'.$import->id.'/fig-'.Str::random(10).'.png';
        Storage::disk('public')->put($relative, $png);

        return '/storage/'.$relative;
    }

    private function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', default => 'image/png',
        };
    }
}
