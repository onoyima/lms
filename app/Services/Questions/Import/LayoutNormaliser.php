<?php

namespace App\Services\Questions\Import;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Lecturers type question papers in many layouts. Before the parser reads a paper, this rewrites
 * the common ones into the layout it expects, so the same file works without retyping:
 *
 *  - options on one line:            "A. Lagos   B. Abuja   C. Kano   D. Ibadan"
 *  - options after the question:     "1. Water boils at  A. 50°C  B. 100°C  C. 150°C"
 *  - options without a full stop:    "A Lagos" / "A: Lagos" / "A - Lagos" (A, B, C … in sequence)
 *  - question numbers written as     "1 What…", "1: What…", "(1) What…", "Question 1: What…", "No. 1 What…"
 *  - an answer key at the end:       "ANSWERS" (or "Answer key", "Marking scheme") then "1. B", "2-C",
 *                                    "1.B 2.C 3.A" on one line, a numbered list or a table
 *  - a table with one question per row and columns Question | A | B | C | D | Answer
 *
 * Text that is already in the expected layout is left exactly as it was.
 */
class LayoutNormaliser
{
    private const KEY_HEADING = '/^\s*(?:the\s+)?(?:answers?|answer\s*keys?|keys?|correct\s+answers?|solutions?|marking\s+(?:scheme|guide)|answer\s+sheet|objective\s+answers?)\s*[:.\-]?\s*$/iu';

    private const KEY_PAIR = '/(?<![\w.])(\d{1,4})\s*[.):\-=]?\s*\(?((?:[A-Ha-h](?:\s*[,&\/]\s*[A-Ha-h])*)|TRUE|FALSE|T|F)\)?\.?(?=\s|$|[,;])/iu';

    /** @var array<int, string> question number => answer from an answer key */
    private array $key = [];

    /**
     * @return array{html: string, key: array<int, string>}
     */
    public function normalise(string $html): array
    {
        $this->key = [];
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return ['html' => $html, 'key' => []];
        }

        // 1. tables of questions, and the answer key, are read from the document's own structure
        $nodes = $this->flatten($body);
        $nodes = $this->questionTables($nodes);
        $nodes = $this->answerKey($nodes);

        // 2. one paragraph each, as HTML strings, then the line-by-line rewrites
        $blocks = [];
        foreach ($nodes as $n) {
            $blocks = array_merge($blocks, $this->splitOptions($n));
        }
        $blocks = $this->sequence($blocks);

        return ['html' => implode("\n", array_map(fn ($b) => $b['html'], $blocks)), 'key' => $this->key];
    }

    /**
     * Top-level blocks, looking inside div/section wrappers.
     *
     * @return list<array{html: string, text: string, node: ?DOMNode}>
     */
    private function flatten(DOMNode $parent): array
    {
        $out = [];
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['div', 'section', 'article'], true)) {
                $out = array_merge($out, $this->flatten($node));

                continue;
            }
            if (! $node instanceof DOMElement && trim($node->textContent) === '') {
                continue;
            }
            $html = $node->ownerDocument->saveHTML($node);
            $out[] = ['html' => $html, 'text' => self::text($node), 'node' => $node];
        }

        return $out;
    }

    private static function text(DOMNode $n): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $n->textContent));
    }

    private static function plain(string $html): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function p(string $inner, ?string $line = null): array
    {
        $attr = $line !== null ? ' data-line="'.$line.'"' : '';

        return ['html' => "<p$attr>$inner</p>", 'text' => self::plain($inner), 'node' => null];
    }

    // ── tables with one question per row ─────────────────────────────────────

    private function questionTables(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $el = $n['node'];
            if (! $el instanceof DOMElement || strtolower($el->tagName) !== 'table') {
                $out[] = $n;

                continue;
            }
            $rows = [];
            foreach ($el->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                        $inner = '';
                        foreach ($td->childNodes as $c) {
                            $inner .= $td->ownerDocument->saveHTML($c);
                        }
                        // paragraphs inside a cell become line breaks
                        $inner = trim(preg_replace(['#</p>\s*<p[^>]*>#i', '#</?p[^>]*>#i'], ['<br>', ''], $inner));
                        $cells[] = ['html' => $inner, 'text' => self::text($td)];
                    }
                }
                $rows[] = $cells;
            }
            $map = $rows ? $this->tableColumns(array_column($rows[0], 'text')) : null;
            // "Question | Answer" with only numbers in the question column is an answer key, not questions
            if ($map && count($map['options']) < 2 && ! array_filter(array_slice($rows, 1), fn ($r) => preg_match('/\p{L}{2,}/u', $r[$map['question']]['text'] ?? ''))) {
                $map = null;
            }
            if (! $map) {
                $out[] = $n;

                continue;
            }
            $num = 0;
            foreach (array_slice($rows, 1) as $cells) {
                $stem = $cells[$map['question']]['html'] ?? '';
                if (self::plain($stem) === '') {
                    continue;
                }
                $num = isset($map['number']) && preg_match('/^\d{1,4}$/', $cells[$map['number']]['text'] ?? '') ? (int) $cells[$map['number']]['text'] : $num + 1;
                $out[] = self::p($num.'. '.$stem);
                foreach ($map['options'] as $label => $i) {
                    if (($cells[$i]['text'] ?? '') !== '') {
                        $out[] = self::p($label.'. '.$cells[$i]['html']);
                    }
                }
                if (isset($map['answer']) && ($cells[$map['answer']]['text'] ?? '') !== '') {
                    $out[] = self::p('Answer: '.htmlspecialchars($cells[$map['answer']]['text'], ENT_QUOTES, 'UTF-8'));
                }
                if (isset($map['mark']) && ($cells[$map['mark']]['text'] ?? '') !== '') {
                    $out[] = self::p('Mark: '.htmlspecialchars($cells[$map['mark']]['text'], ENT_QUOTES, 'UTF-8'));
                }
                $out[] = ['html' => '<hr>', 'text' => '', 'node' => null];
            }
        }

        return $out;
    }

    /** Header row "S/N | Question | A | B | C | D | Answer": which column is which, or null. */
    private function tableColumns(array $head): ?array
    {
        $map = ['options' => []];
        foreach ($head as $i => $h) {
            $h = strtolower(trim($h, " .:\t"));
            if (preg_match('/^(s\/?n|no|q\.?\s*no|#|number|num)$/', $h)) {
                $map['number'] = $i;
            } elseif (preg_match('/^(questions?|question\s+text|stem)$/', $h)) {
                $map['question'] = $i;
            } elseif (preg_match('/^(?:option\s*)?([a-h])$/', $h, $m)) {
                $map['options'][strtoupper($m[1])] = $i;
            } elseif (preg_match('/^(answers?|ans|key|correct(\s+(answer|option))?)$/', $h)) {
                $map['answer'] = $i;
            } elseif (preg_match('/^(marks?|score)$/', $h)) {
                $map['mark'] = $i;
            }
        }

        return isset($map['question']) && (count($map['options']) >= 2 || isset($map['answer'])) ? $map : null;
    }

    // ── answer key at the end ────────────────────────────────────────────────

    private function answerKey(array $nodes): array
    {
        $count = count($nodes);
        for ($i = $count - 1; $i >= 1; $i--) {
            if (! preg_match(self::KEY_HEADING, $nodes[$i]['text'])) {
                continue;
            }
            $pairs = [];
            $ok = $i < $count - 1;
            for ($j = $i + 1; $j < $count && $ok; $j++) {
                $found = $this->keyPairs($nodes[$j]);
                if ($found === null) {
                    $ok = false;
                } else {
                    $pairs += $found;
                }
            }
            if ($ok && $pairs) {
                $this->key = $pairs;

                return array_slice($nodes, 0, $i);
            }
        }

        // no heading: a run of three or more bare "1. B" lines closing the document
        $pairs = [];
        $i = $count;
        while ($i > 0 && preg_match('/^\s*\d{1,4}\s*[.):\-=]?\s*\(?[A-Ha-h]\)?\.?\s*$/u', $nodes[$i - 1]['text'])) {
            $i--;
            $pairs += $this->keyPairs($nodes[$i]) ?? [];
        }
        if ($count - $i >= 3 && $i > 0 && count($pairs) === $count - $i) {
            // the lines must not just be a numbered question with options "1. A" … (they would have options after them)
            $this->key = $pairs;

            return array_slice($nodes, 0, $i);
        }

        return $nodes;
    }

    /** @return array<int, string>|null the "number => answer" pairs in a block, or null if it is not key-like */
    private function keyPairs(array $n): ?array
    {
        $el = $n['node'];
        if ($el instanceof DOMElement && in_array(strtolower($el->tagName), ['ol', 'ul'], true)) {
            // a numbered list of letters
            $start = (int) ($el->getAttribute('start') ?: 1);
            $out = [];
            $k = 0;
            foreach ($el->childNodes as $li) {
                if (! $li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                    continue;
                }
                $t = trim(self::text($li), " .()");
                if (preg_match('/^\d{1,4}\s*[.):\-=]?\s*\(?([A-Ha-h](?:\s*[,&\/]\s*[A-Ha-h])*|TRUE|FALSE|T|F)\)?\.?$/iu', $t, $m)) {
                    $out[(int) $t] = $m[1];
                } elseif (preg_match('/^([A-Ha-h](?:\s*[,&\/]\s*[A-Ha-h])*|TRUE|FALSE|T|F)$/iu', $t)) {
                    $out[$start + $k] = $t;
                } else {
                    return null;
                }
                $k++;
            }

            return $out;
        }
        $text = $n['text'];
        if ($el instanceof DOMElement && strtolower($el->tagName) === 'table') {
            $text = '';
            foreach ($el->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    $td instanceof DOMElement && $cells[] = self::text($td);
                }
                $text .= ' '.implode(' ', $cells);
            }
            $text = trim($text);
            // a header row such as "Question | Answer"
            $text = preg_replace('/^(?:(?:s\/?n|no|q(?:uestion)?s?|answers?|ans|keys?)\s*)+/i', '', $text);
        }
        if ($text === '') {
            return [];
        }
        if (! preg_match_all(self::KEY_PAIR, $text, $mm, PREG_SET_ORDER)) {
            return null;
        }
        // everything on the line must be pairs (so a sentence is never taken for a key)
        $rest = trim(preg_replace(self::KEY_PAIR, '', $text), " \t,;.|");
        if ($rest !== '') {
            return null;
        }
        $out = [];
        foreach ($mm as $m) {
            $out[(int) $m[1]] = $m[2];
        }

        return $out;
    }

    // ── options written on one line ──────────────────────────────────────────

    /** @return list<array{html: string, text: string, node: ?DOMNode}> */
    private function splitOptions(array $n): array
    {
        $el = $n['node'];
        if ($el instanceof DOMElement && ! in_array(strtolower($el->tagName), ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            return [$n];
        }
        if (! preg_match('/(?:^|\s)\(?[Aa][.)]\s/u', $n['text'])) {
            return [$n];
        }
        if (! preg_match('/^<(p|li|h\d)(\s[^>]*)?>(.*)<\/\1>\s*$/is', trim($n['html']), $outer)) {
            return [$n];
        }
        $line = preg_match('/data-line="(\d+)"/', $outer[2] ?? '', $lm) ? $lm[1] : null;
        $inner = $outer[3];

        // where each "A." "B." … sits in the HTML (text only, never inside a tag)
        $marks = [];
        $offset = 0;
        foreach (preg_split('/(<[^>]+>)/u', $inner, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if ($part !== '' && $part[0] !== '<') {
                $plain = str_replace('&nbsp;', ' ', $part);
                if (preg_match_all('/(?<=^|[\s(])(\(?)([A-Ha-h])[.)](?=\s|$)/u', $plain, $mm, PREG_OFFSET_CAPTURE)) {
                    foreach ($mm[2] as $k => [$letter]) {
                        // back to the offset in $part (where &nbsp; takes 6 bytes, not 1)
                        $real = $this->realOffset($part, $mm[0][$k][1]);
                        $marks[] = ['letter' => $letter, 'at' => $offset + $real];
                    }
                }
            }
            $offset += strlen($part);
        }

        // the longest run A, B, C … (same case) in order
        $best = [];
        foreach ($marks as $s => $m) {
            if (strtoupper($m['letter']) !== 'A') {
                continue;
            }
            $run = [$m];
            $next = ord($m['letter']) + 1;
            foreach (array_slice($marks, $s + 1) as $m2) {
                if (ord($m2['letter']) === $next) {
                    $run[] = $m2;
                    $next++;
                }
            }
            if (count($run) > count($best)) {
                $best = $run;
            }
        }
        $leading = $best && trim(self::plain(substr($inner, 0, $best[0]['at']))) === '';
        // a line that starts with A. needs B. too; options after the question text need A, B and C
        if (count($best) < ($leading ? 2 : 3)) {
            return [$n];
        }
        if (! $leading && ! preg_match('/^\s*(?:Q(?:uestion)?\s*)?\(?\d{1,4}\s*[.):]/iu', $n['text'])) {
            return [$n]; // only split the options off a numbered question line
        }

        $pieces = [];
        $cuts = array_column($best, 'at');
        $start = 0;
        foreach ($cuts as $c) {
            $pieces[] = substr($inner, $start, $c - $start);
            $start = $c;
        }
        $pieces[] = substr($inner, $start);
        $out = [];
        foreach ($pieces as $piece) {
            if (self::plain($piece) !== '') {
                $out[] = self::p(trim(self::balance($piece)), $line);
            }
        }

        return $out;
    }

    /** Offset in a text part (which may contain &nbsp;) of a position in its plain version. */
    private function realOffset(string $part, int $plainPos): int
    {
        $real = 0;
        $plain = 0;
        $len = strlen($part);
        while ($plain < $plainPos && $real < $len) {
            if (substr($part, $real, 6) === '&nbsp;') {
                $real += 6;
            } else {
                $real++;
            }
            $plain++;
        }

        return $real;
    }

    /** Close tags left open by a cut, and drop closing tags with no opening tag. */
    private static function balance(string $html): string
    {
        $stack = [];
        $out = '';
        foreach (preg_split('/(<\/?[a-z][a-z0-9]*\b[^>]*>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if (preg_match('/^<(\/?)([a-z][a-z0-9]*)\b[^>]*?(\/?)>$/i', $part, $m)) {
                $tag = strtolower($m[2]);
                if (in_array($tag, ['br', 'img', 'hr', 'input', 'wbr'], true) || $m[3] === '/') {
                    $out .= $part;
                } elseif ($m[1] === '/') {
                    if (($i = array_search($tag, array_reverse($stack, true), true)) !== false) {
                        while (count($stack) > $i) {
                            $out .= '</'.array_pop($stack).'>';
                        }
                    }
                } else {
                    $stack[] = $tag;
                    $out .= $part;
                }
            } else {
                $out .= $part;
            }
        }
        while ($stack) {
            $out .= '</'.array_pop($stack).'>';
        }

        return $out;
    }

    // ── question numbers and option letters written loosely ──────────────────

    /**
     * @param  list<array{html: string, text: string, node: ?DOMNode}>  $blocks
     * @return list<array{html: string, text: string, node: ?DOMNode}>
     */
    private function sequence(array $blocks): array
    {
        $last = 0;       // number of the last question seen
        $sinceQ = true;  // options or an answer came after it (so a new number really is a new question)
        $nextLetter = null;
        $count = count($blocks);
        for ($i = 0; $i < $count; $i++) {
            $t = $blocks[$i]['text'];
            if ($t === '') {
                continue;
            }
            // already "1." / "Q1)"
            if (preg_match('/^\s*(?:Q(?:uestion)?\s*)?(\d{1,4})\s*[.)]\s+/iu', $t, $m)) {
                $last = (int) $m[1];
                $sinceQ = false;
                $nextLetter = 'A';

                continue;
            }
            // "1 What", "1: What", "(1) What", "Question 1: What", "Q1: What", "No. 1 What"
            if (preg_match('/^\s*(?:(?:question|ques|qn|q|no\.?)\s*)?\(?(\d{1,4})\s*(?:\)|:|-|–|\.)?\s+(?=[\p{L}"“‘\'(\[$\\\\<])/iu', $t, $m)
                && ((int) $m[1] === $last + 1 || ($last === 0 && (int) $m[1] <= 1)) && ($sinceQ || $last === 0)) {
                $blocks[$i] = $this->rewritePrefix($blocks[$i], '/^\s*(?:(?:question|ques|qn|q|no\.?)\s*)?\(?\d{1,4}\s*(?:\)|:|-|–|\.)?\s+/iu', $m[1].'. ');
                $last = (int) $m[1];
                $sinceQ = false;
                $nextLetter = 'A';

                continue;
            }
            // standard "A." option lines keep the letter count going
            if (preg_match('/^\s*\*?\s*\(?([A-Ha-h])[.)]\s+/u', $t, $m)) {
                $nextLetter = chr(ord(strtoupper($m[1])) + 1);
                $sinceQ = true;

                continue;
            }
            if (preg_match('/^\s*(Answers?|Ans|Key|Correct)\b/iu', $t)) {
                $sinceQ = true;

                continue;
            }
            // "A Lagos" / "A: Lagos" / "A - Lagos": only as a run A, B … in order
            if ($nextLetter && preg_match('/^\s*(\*?)\s*([A-Ha-h])\s*(?:[:\-–]\s*|\s+)\S/u', $t, $m) && strtoupper($m[2]) === $nextLetter) {
                $run = $nextLetter !== 'A';
                if (! $run) {
                    // look ahead for the B line
                    for ($j = $i + 1; $j < $count && $blocks[$j]['text'] === ''; $j++) {
                    }
                    $run = $j < $count && preg_match('/^\s*\*?\s*'.(ctype_upper($m[2]) ? 'B' : 'b').'(?:\s*[.):\-–]\s*|\s+)\S/u', $blocks[$j]['text']);
                }
                if ($run) {
                    $blocks[$i] = $this->rewritePrefix($blocks[$i], '/^\s*(\*?)\s*([A-Ha-h])\s*(?:[:\-–]\s*|\s+)/u', null);
                    $nextLetter = chr(ord($nextLetter) + 1);
                    $sinceQ = true;
                }
            }
        }

        return $blocks;
    }

    /** Replace the prefix at the start of a block's text, skipping any opening tags before it. */
    private function rewritePrefix(array $b, string $regex, ?string $replacement): array
    {
        $html = $b['html'];
        // find where the text starts: after the opening tags
        if (! preg_match('/^((?:\s*<[^\/][^>]*>)*)(.*)$/s', $html, $parts)) {
            return $b;
        }
        $body = str_replace('&nbsp;', ' ', $parts[2]);
        $new = preg_replace_callback($regex, function ($m) use ($replacement) {
            return $replacement ?? ($m[1].$m[2].'. ');
        }, $body, 1);
        $b['html'] = $parts[1].$new;
        $b['text'] = self::plain($b['html']);

        return $b;
    }
}
