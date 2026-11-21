<?php

namespace App\Services\Questions\Import;

/**
 * Questions from a spreadsheet saved as CSV (Excel "CSV UTF-8" or plain CSV; commas, semicolons or
 * tabs). Each row becomes the same text lines a setter would type, so CSV, text and Word files are
 * read by one set of rules and give the same questions.
 *
 * Columns are found by their heading (any order, case and spacing ignored):
 *   question · type · option a … option h · answer (or correct option) · mark · topic · difficulty
 *   explanation · image (or question image) · option a image … option d image · statements · accept
 *   · scoring
 * Files without recognisable headings use the old exam system's two layouts by position:
 *   question, A, B, C, D, answer[, mark]                                  (7 columns)
 *   question, image, A, A image, B, B image, C, C image, D, D image, answer, mark[, type, statements]
 */
class CsvQuestionReader
{
    private const ALIASES = [
        'question' => ['question', 'questiontext', 'stem', 'questions', 'text'],
        'type' => ['type', 'questiontype', 'kind'],
        'answer' => ['answer', 'answers', 'correct', 'correctoption', 'correctanswer', 'key', 'correctoptionad', 'correctoptionah'],
        'mark' => ['mark', 'marks', 'score', 'points'],
        'topic' => ['topic', 'bank', 'subject'],
        'difficulty' => ['difficulty', 'level'],
        'explanation' => ['explanation', 'reason', 'feedback', 'rationale'],
        'image' => ['image', 'questionimage', 'picture', 'diagram'],
        'statements' => ['statements', 'mtfstatements'],
        'accept' => ['accept', 'alsoaccept', 'acceptedanswers'],
        'scoring' => ['scoring'],
    ];

    /** True when a .txt file is really a CSV with a question heading (the old system's samples are). */
    public static function looksLikeCsv(string $text): bool
    {
        $first = strtok(ltrim($text, "\xEF\xBB\xBF"), "\n") ?: '';

        return (bool) preg_match('/^\s*"?question[ _]?(text)?"?\s*[,;\t]/i', $first) && substr_count($first, ',') + substr_count($first, ';') + substr_count($first, "\t") >= 3;
    }

    /** @return string HTML in the parser's text layout (one <p> per line, <hr> between questions) */
    public function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $delimiter = $this->delimiter(strtok($text, "\n") ?: '');
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $text);
        rewind($fh);

        $rows = [];
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($c) => trim((string) $c), $row);
        }
        fclose($fh);
        if (! $rows) {
            return '';
        }

        $map = $this->headings($rows[0]);
        $start = $map !== null || preg_match('/question/i', $rows[0][0] ?? '') ? 1 : 0;
        $out = [];
        foreach (array_slice($rows, $start, null, true) as $i => $row) {
            if (! array_filter($row, fn ($c) => $c !== '')) {
                continue;
            }
            $line = $i + 1;
            $lines = $map !== null ? $this->named($row, $map) : $this->positional($row);
            if ($out) {
                $out[] = '<hr data-gap="1">';
            }
            foreach ($lines as $l) {
                $out[] = '<p data-line="'.$line.'">'.DocumentConverter::markMaths(htmlspecialchars($l, ENT_QUOTES, 'UTF-8')).'</p>';
            }
        }

        return implode("\n", $out);
    }

    private function delimiter(string $first): string
    {
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** @return array<string,int>|null column positions by meaning, or null when the first row is not headings */
    private function headings(array $row): ?array
    {
        $map = [];
        foreach ($row as $i => $h) {
            $k = preg_replace('/[^a-z0-9]/', '', strtolower($h));
            if (preg_match('/^option([a-h])image$/', $k, $m) || preg_match('/^([a-h])image$/', $k, $m)) {
                $map['option_image_'.$m[1]] = $i;

                continue;
            }
            if (preg_match('/^(?:option|opt|choice)?([a-h])$/', $k, $m) && $k !== '' && ($k !== $m[1] || strlen($h) <= 2)) {
                $map['option_'.$m[1]] = $i;

                continue;
            }
            foreach (self::ALIASES as $name => $aliases) {
                if (in_array($k, $aliases, true) && ! isset($map[$name])) {
                    $map[$name] = $i;
                }
            }
        }

        return isset($map['question']) && (isset($map['answer']) || isset($map['option_a']) || isset($map['type'])) ? $map : null;
    }

    /** @return list<string> */
    private function named(array $row, array $map): array
    {
        $get = fn (string $k) => isset($map[$k]) ? trim((string) ($row[$map[$k]] ?? '')) : '';
        $lines = [];
        foreach (['type' => 'TYPE', 'mark' => 'MARK', 'scoring' => 'SCORING', 'image' => 'IMAGE'] as $k => $label) {
            $get($k) !== '' && $lines[] = "$label: ".$get($k);
        }
        $lines[] = self::stem($get('question'));
        foreach (range('a', 'h') as $letter) {
            $text = $get('option_'.$letter);
            $image = $get('option_image_'.$letter);
            if ($text !== '' || $image !== '') {
                $lines[] = strtoupper($letter).'. '.trim($text.($image !== '' ? ' {{image:'.$image.'}}' : ''));
            }
        }
        foreach (preg_split('/\s*\|\|\s*/', $get('statements')) as $j => $statement) {
            $statement !== '' && $lines[] = chr(97 + $j).') '.$statement;
        }
        foreach (['answer' => 'ANSWER', 'topic' => 'Topic', 'difficulty' => 'Difficulty', 'accept' => 'Accept', 'explanation' => 'Explanation'] as $k => $label) {
            $get($k) !== '' && $lines[] = "$label: ".str_replace("\n", ' ', $get($k));
        }

        return $lines;
    }

    /** The question text on one line, without a leading number (each row is one question). */
    private static function stem(string $text): string
    {
        $text = trim(preg_replace('/\s*\n\s*/', ' ', $text));

        return preg_replace('/^(?:Q(?:uestion)?\s*)?\d{1,4}\s*[.):]\s+/i', '', $text) ?: '(question text missing)';
    }

    /** The old exam system's layouts, by column position. @return list<string> */
    private function positional(array $row): array
    {
        $row = array_pad($row, 14, '');
        if (count(array_filter(array_slice($row, 6), fn ($c) => $c !== '')) >= 3 || $row[10] !== '') {
            $lines = [];
            $row[12] !== '' && $lines[] = 'TYPE: '.$row[12];
            $row[11] !== '' && $lines[] = 'MARK: '.$row[11];
            $row[1] !== '' && $lines[] = 'IMAGE: '.$row[1];
            $lines[] = self::stem($row[0]);
            foreach (['A' => 2, 'B' => 4, 'C' => 6, 'D' => 8] as $letter => $col) {
                if ($row[$col] !== '' || $row[$col + 1] !== '') {
                    $lines[] = "$letter. ".trim($row[$col].($row[$col + 1] !== '' ? ' {{image:'.$row[$col + 1].'}}' : ''));
                }
            }
            foreach (preg_split('/\s*\|\|\s*/', $row[13]) as $j => $statement) {
                $statement !== '' && $lines[] = chr(97 + $j).') '.$statement;
            }
            $row[10] !== '' && $lines[] = 'ANSWER: '.$row[10];

            return $lines;
        }
        $lines = [];
        $row[6] !== '' && $lines[] = 'MARK: '.$row[6];
        $lines[] = self::stem($row[0]);
        foreach (['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4] as $letter => $col) {
            $row[$col] !== '' && $lines[] = "$letter. ".$row[$col];
        }
        $row[5] !== '' && $lines[] = 'ANSWER: '.$row[5];

        return $lines;
    }
}
