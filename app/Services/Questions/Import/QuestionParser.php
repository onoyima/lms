<?php

namespace App\Services\Questions\Import;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Splits HTML (from Word via pandoc, or from text) into questions.
 *
 * Template a setter follows (Word or plain text):
 *
 *   1. What is the product of $\ce{2H2 + O2 ->}$ ?
 *   A. $\ce{H2O}$
 *   *B. $\ce{2H2O}$          <- a leading or trailing * marks the correct option
 *   C. $\ce{H2O2}$
 *   D. $\ce{O3}$
 *   Mark: 2                  <- optional lines: Mark, Topic, Difficulty, Type, Explanation
 *   Answer: B                <- alternative to the *, multiple answers "Answer: A, C"
 *
 * Multiple true/false: lettered statements, then "Type: MTF" and the truth of each statement,
 * "Answer: TFTFT" or "Answer: A-T, B-F, C-T, D-F, E-T" (or the * on each true statement).
 *
 * Word's own numbered lists work too: a numbered list item starts a question and
 * a lettered (A, B, C) sub-list holds its options.
 *
 * The old exam system's plain layout ("Aiken") works unchanged: questions without numbers, each
 * ending with an "ANSWER: B" line; TYPE:, MARK:, IMAGE: and SCORING: lines before a question;
 * multiple true/false statements marked [T] / [F] or "ANSWER: TFFT"; "TYPE: TF" with
 * "ANSWER: TRUE". In text files a blank line also ends a question whose statements are all marked.
 */
class QuestionParser
{
    private const QUESTION_START = '/^\s*(?:Q(?:uestion)?\s*)?(\d{1,4})\s*[.)]\s+/iu';

    private const OPTION_START = '/^\s*(\*)?\s*\(?([A-Ha-h])[.)]\s+/u';

    private const META = '/^\s*(Answers?|Ans|Key|Correct(?:\s+(?:answer|option))?|Mark|Marks|Score|Scoring|Image|Topic|Difficulty|Type|Explanation|Pair|Match|Accept|Also accept|Outcome|Tolerance)\s*(?:[:=\-]|\.(?=\s))\s*(.*)$/isu';

    // The same prefixes, for removing them from the HTML (they can sit behind opening tags)
    private const Q_PREFIX = '(?:Q(?:uestion)?\s*)?\d{1,4}\s*[.)]\s+';

    private const O_PREFIX = '\*?\s*\(?[A-Ha-h][.)]\s+';

    private const M_PREFIX = '(?:Answers?|Ans|Key|Correct(?:\s+(?:answer|option))?|Mark|Marks|Score|Scoring|Image|Topic|Difficulty|Type|Explanation|Pair|Match|Accept|Also accept|Outcome|Tolerance)\s*(?:[:=\-]|\.(?=\s))\s*';

    /** @var list<ParsedQuestion> */
    private array $questions = [];

    private ?ParsedQuestion $current = null;

    private string $section = 'stem'; // stem, option, explanation

    /** The current (unnumbered) question has had its Answer line: plain text after it starts the next one. */
    private bool $closed = false;

    /** A blank line came just before this block (text files only). */
    private bool $afterGap = false;

    /** Type/Mark/Image/Scoring lines written before the question they belong to (old layout). */
    private array $pending = [];

    /** Line of the file of the block being read, when known. */
    private ?int $line = null;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @return array{questions: list<ParsedQuestion>, warnings: list<string>}
     */
    public function parse(string $html): array
    {
        $this->questions = [];
        $this->current = null;
        $this->warnings = [];
        $this->pending = [];
        $this->closed = $this->afterGap = false;
        $this->line = null;

        // common layouts (options on one line, "A Lagos", "1 What…", an answer key at the end, a table of questions)
        ['html' => $html, 'key' => $key] = (new LayoutNormaliser)->normalise($html);

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);
        foreach ($this->blocks($body) as [$kind, $node, $extra]) {
            $this->line = $node instanceof DOMElement && $node->hasAttribute('data-line') ? (int) $node->getAttribute('data-line') : null;
            // an empty paragraph (a blank line in Word) counts as a blank line
            if ($kind === 'block' && $node instanceof DOMElement && trim($node->textContent) === '' && ! preg_match('/<(img|table)\b/i', (string) $node->ownerDocument->saveHTML($node))) {
                $kind = 'gap';
            }
            if ($kind === 'gap') {
                $this->afterGap = true;

                continue;
            }
            $this->consume($kind, $node, $extra);
            $this->afterGap = false;
        }
        $this->finish();
        if ($key) {
            $this->applyKey($key);
        }
        if ($this->pending) {
            $this->warnings[] = $this->at($this->pending['_line'] ?? null).'Lines such as TYPE: or MARK: at the end of the file have no question after them.';
        }

        return ['questions' => $this->questions, 'warnings' => $this->warnings];
    }

    /**
     * Flatten the document into an ordered list of blocks:
     * ['question', li, number] for numbered-list items, ['option', li, label] for lettered-list items,
     * ['block', node, null] for paragraphs, headings, tables, images.
     *
     * @return \Generator<array{0:string,1:DOMNode,2:mixed}>
     */
    private function blocks(DOMNode $parent): \Generator
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if ($tag === 'hr') {
                    yield ['gap', $node, null];

                    continue;
                }
                if ($tag === 'ol') {
                    yield from $this->listItems($node);

                    continue;
                }
                if (in_array($tag, ['ul'], true)) {
                    foreach ($this->childElements($node, 'li') as $li) {
                        yield ['block', $li, null];
                    }

                    continue;
                }
                if (in_array($tag, ['div', 'section', 'article', 'blockquote'], true)) {
                    yield from $this->blocks($node);

                    continue;
                }
                yield ['block', $node, null];
            } elseif ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) !== '') {
                yield ['block', $node, null];
            }
        }
    }

    /** @return \Generator<array{0:string,1:DOMNode,2:mixed}> */
    private function listItems(DOMElement $ol): \Generator
    {
        $type = $ol->getAttribute('type');
        $isLetters = in_array($type, ['A', 'a'], true) || str_contains($ol->getAttribute('style'), 'alpha');
        $start = (int) ($ol->getAttribute('start') ?: 1);
        $i = 0;
        foreach ($this->childElements($ol, 'li') as $li) {
            yield $isLetters ? ['option', $li, chr(ord('A') + $start - 1 + $i)] : ['question', $li, $start + $i];
            $i++;
        }
    }

    /** @return list<DOMElement> */
    private function childElements(DOMElement $el, string $tag): array
    {
        $out = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && strtolower($c->tagName) === $tag) {
                $out[] = $c;
            }
        }

        return $out;
    }

    private function consume(string $kind, DOMNode $node, mixed $extra): void
    {
        if ($kind === 'question') {
            // A Word list item holds the stem, and may hold a nested lettered list of options.
            $this->startQuestion((int) $extra);
            $this->appendStem($this->html($node));
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ol', 'ul'], true)) {
                    $items = strtolower($child->tagName) === 'ol' ? $this->listItems($child) : (function () use ($child) {
                        foreach ($this->childElements($child, 'li') as $li) {
                            yield ['block', $li, null];
                        }
                    })();
                    foreach ($items as [$k, $n, $x]) {
                        $this->consume($k === 'question' ? 'block' : $k, $n, $x);
                    }
                }
            }

            return;
        }

        if ($kind === 'option') {
            $this->ensureQuestion();
            $html = $this->html($node);
            [$html, $starred] = $this->stripStar($html);
            $this->addOption((string) $extra, $html, $starred);

            return;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        if (str_starts_with($text, '//')) {
            return; // a note for the person typing the file
        }
        $html = $this->html($node);
        if ($text === '' && ! preg_match('/<(img|table)\b/i', $html)) {
            return;
        }

        if (preg_match(self::QUESTION_START, $text, $m)) {
            $this->startQuestion((int) $m[1]);
            $this->appendStem($this->stripPrefix($html, self::Q_PREFIX));

            return;
        }

        // In the old layout, a blank line ends a question whose statements all carry [T] / [F].
        // (So does it end a question whose type needs no options or Answer line, such as TYPE: ESSAY.)
        if ($this->current?->unnumbered && $this->afterGap && ($this->allStatementsMarked($this->current)
            || ($this->current->typeGiven && ! in_array($this->current->type, ['single_choice', 'multiple_choice', 'true_false', 'multi_true_false'], true)))) {
            $this->closed = true;
        }

        if ($this->current && ! $this->closed && preg_match(self::OPTION_START, $text, $m) && $this->section !== 'explanation') {
            $html = $this->stripPrefix($html, self::O_PREFIX);
            [$html, $trailingStar] = $this->stripStar($html);
            $this->addOption(strtoupper($m[2]), $html, $m[1] === '*' || $trailingStar);

            return;
        }

        if (preg_match(self::META, $text, $m)) {
            $key = strtolower($m[1]);
            $key = match ($key) { 'answers', 'key' => 'answer', default => $key };
            $value = trim($m[2]);
            // Old layout: TYPE:/IMAGE:/SCORING: (and anything after a blank line) belong to the next question.
            $forNext = ! $this->current
                || ($this->current->unnumbered && $key === 'type') // TYPE: always opens the next question in the old layout
                || ($this->current->unnumbered && $this->closed && ($this->pending || $this->afterGap || in_array($key, ['image', 'scoring'], true)));
            if ($forNext) {
                if ($key === 'answer' && ! $this->current) {
                    $this->warnings[] = $this->at($this->line).'An ANSWER line with no question above it.';

                    return;
                }
                if ($key === 'type' && $this->current) {
                    $this->finish();
                }
                $this->pending['_line'] ??= $this->line;
                $this->pending[$key] = [$value, $html];

                return;
            }
            $this->applyMeta($key, $value, $html);

            return;
        }

        // Plain text with no number: a new question in the old layout (or the very first question).
        if (! $this->current || ($this->current->unnumbered && $this->closed)) {
            $this->startQuestion(count($this->questions) + 1, true);
            $this->appendStem($html);

            return;
        }

        match ($this->section) {
            'stem' => $this->appendStem($html),
            'option' => $this->appendToLastOption($html),
            'explanation' => $this->current->explanationHtml .= $html,
        };
    }

    private function startQuestion(int $number, bool $unnumbered = false): void
    {
        $this->finish();
        $this->current = new ParsedQuestion($number);
        $this->current->unnumbered = $unnumbered;
        $this->current->line = $this->pending['_line'] ?? $this->line;
        $this->section = 'stem';
        $this->closed = false;
        $pending = $this->pending;
        $this->pending = [];
        unset($pending['_line']);
        foreach ($pending as $key => [$value, $html]) {
            $this->applyMeta($key, $value, $html);
        }
        $this->section = 'stem';
        $this->closed = false;
    }

    private function at(?int $line): string
    {
        return $line ? "Line $line: " : '';
    }

    /** Every statement carries its own [T] / [F] mark (old multiple true/false layout). */
    private function allStatementsMarked(ParsedQuestion $q): bool
    {
        if (count($q->options) < 2 || ($q->typeGiven && $q->type !== 'multi_true_false')) {
            return false;
        }
        foreach ($q->options as $o) {
            if (($o['tf'] ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    private function ensureQuestion(): void
    {
        if (! $this->current) {
            $this->startQuestion(count($this->questions) + 1);
        }
    }

    private function appendStem(string $html): void
    {
        // "Which are noble gases? Answer: A, C" written on the question line itself
        if (preg_match('/\s*\b(?:Answer|Ans)\s*[:\-]\s*([A-Ha-h](?:\s*[,;&]\s*[A-Ha-h])*)\s*$/u', $html, $m)) {
            $html = substr($html, 0, -strlen($m[0]));
            $this->current->answerText = strtoupper($m[1]);
        }
        $this->current->stemHtml .= $this->wrapBlock($html);
    }

    private function addOption(string $label, string $html, bool $correct): void
    {
        $this->section = 'option';
        $this->current->options[] = ['label' => $label, 'html' => trim($html), 'correct' => $correct, 'tf' => self::trueFalseMark(trim($html))];
    }

    /** "[T]", "(F)", "- TRUE", "= FALSE" at the end of a statement: its truth (old layout), or null. */
    public static function trueFalseMark(string $html): ?array
    {
        foreach (['/\s*[\[(]\s*(T|F|TRUE|FALSE)\s*[\])]\s*$/i', '/\s+[-–=|:]\s*(T|F|TRUE|FALSE)\s*$/i', '/\s+(TRUE|FALSE)\s*$/', '/\s{2,}(T|F)$/'] as $p) {
            if (preg_match($p, $html, $m)) {
                return [strtoupper($m[1][0]) === 'T', trim(preg_replace($p, '', $html))];
            }
        }

        return null;
    }

    private function appendToLastOption(string $html): void
    {
        $i = array_key_last($this->current->options);
        $this->current->options[$i]['html'] .= ' '.$html;
        $this->current->options[$i]['tf'] = self::trueFalseMark($this->current->options[$i]['html']);
    }

    private function applyMeta(string $key, string $value, string $html): void
    {
        $q = $this->current;
        $q->hasMeta = true;
        if (in_array($key, ['correct option'], true)) {
            $key = 'answer';
        }
        if (in_array($key, ['answer', 'ans', 'correct', 'correct answer'], true)) {
            // "(b)", "b)", "Option B", "B." → "B"
            $value = preg_replace('/^(?:option\s+)?\(?([A-Ha-h])\)?\.?$/iu', '$1', trim($value));
            $value = preg_replace_callback('/^\(?([A-Ha-h])\)\s*(?=[,&]|and\b)/iu', fn ($m) => $m[1], $value);
        }
        if (in_array($key, ['answer', 'ans', 'correct', 'correct answer'], true) && $q->unnumbered) {
            $this->closed = true; // old layout: the ANSWER line ends the question
        }
        switch ($key) {
            case 'image':
                $q->image = mb_substr(basename(str_replace('\\', '/', $value)), 0, 190) ?: null;
                break;
            case 'scoring':
                $v = strtolower($value);
                $q->scoring = in_array($v, ['all', 'all_or_nothing', 'exact', 'strict', 'none'], true) ? 'all' : (in_array($v, ['partial', 'proportional'], true) ? 'partial' : null);
                $q->scoring === null && $value !== '' && $q->warnings[] = "SCORING: \"$value\" is not known; write ALL or PARTIAL.";
                break;
            case 'answer': case 'ans': case 'correct': case 'correct answer':
                // "ANSWER: B. Paris": keep the letter, ignore the copy of the option text
                if ($q->options && preg_match('/^([A-Ha-h])(?:[.)]\s+|\s*[:\-]\s+)\S/u', $value, $em) && self::truthList($value) === null) {
                    $value = strtoupper($em[1]);
                } elseif ($q->options && preg_match('/^([A-H])\s+(\S.{2,})$/su', $value, $em) && ! preg_match('/^(?:and\s+|&\s*|,\s*)?[A-H](?:\s*[,;&]\s*[A-H])*\.?$/i', $em[2])) {
                    // "ANSWER: A The Z-track method seals…": the letter, then a note that explains it
                    $value = $em[1];
                    $q->explanationHtml ??= '<p>'.htmlspecialchars(trim($em[2]), ENT_QUOTES, 'UTF-8').'</p>';
                }
                if (self::truthList($value) !== null && ! ($q->typeGiven && $q->type !== 'multi_true_false')) {
                    $q->answerText = $value; // multiple true/false, resolved in finish()
                    $q->type = 'multi_true_false';
                    break;
                }
                if (preg_match('/^[A-Ha-h](\s*[,;&]\s*[A-Ha-h])*\s*$/u', $value) || preg_match('/^[A-Ha-h](\s+and\s+[A-Ha-h])*$/iu', $value)) {
                    preg_match_all('/[A-Ha-h]/u', $value, $mm);
                    $labels = array_map('strtoupper', $mm[0]);
                    foreach ($q->options as &$o) {
                        $o['correct'] = $o['correct'] || in_array($o['label'], $labels, true);
                    }
                    unset($o);
                    if (! $q->options) {
                        $q->answerText = $value; // options may follow; resolved in finish()
                    }
                } else {
                    $q->answerText = $value;
                }
                break;
            case 'mark': case 'marks': case 'score':
                $q->mark = max(0, (float) str_replace(',', '.', $value)) ?: 1;
                break;
            case 'topic':
                $q->topic = mb_substr($value, 0, 190);
                break;
            case 'difficulty':
                $v = strtolower($value);
                $q->difficulty = in_array($v, ['easy', 'medium', 'hard'], true) ? $v : null;
                break;
            case 'type':
                $v = str_replace([' ', '-'], '_', strtolower($value));
                $aliases = ['mcq' => 'single_choice', 'mcq_single' => 'single_choice', 'single' => 'single_choice', 'objective' => 'single_choice', 'multi' => 'multiple_choice', 'mcq_multi' => 'multiple_choice', 'multiple' => 'multiple_choice', 'tf' => 'true_false', 'true/false' => 'true_false', 'truefalse' => 'true_false',
                    'essay_question' => 'essay', 'numerical' => 'numeric', 'number' => 'numeric', 'matching_pairs' => 'matching', 'theory' => 'essay', 'short' => 'short_answer',
                    'mtf' => 'multi_true_false', 'multiple_true_false' => 'multi_true_false', 'multiple_true/false' => 'multi_true_false', 'multi_tf' => 'multi_true_false', 'true_false_set' => 'multi_true_false',
                    'blank' => 'fill_blank', 'blanks' => 'fill_blank', 'fill' => 'fill_blank', 'fill_in' => 'fill_blank', 'fill_in_the_blank' => 'fill_blank', 'fill_in_the_blanks' => 'fill_blank', 'cloze' => 'fill_blank',
                    'order' => 'ordering', 'sequence' => 'ordering', 'arrange' => 'ordering', 'upload' => 'file_upload', 'file' => 'file_upload'];
                $q->type = $aliases[$v] ?? $v;
                $q->typeGiven = true;
                if (! in_array($q->type, \App\Models\Question::TYPES, true)) {
                    $q->warnings[] = "Unknown type \"$value\". Use MCQ, MULTI, TF, MTF, essay, short, numeric, blank, matching, order or upload.";
                    $q->type = 'single_choice';
                    $q->typeGiven = false;
                }
                break;
            case 'pair': case 'match':
                // "Pair: Sodium = Na" (also "->" or "|")
                if (preg_match('/^(.+?)\s+(?:=|->|→|\|)\s+(.+)$/u', $value, $pm)) {
                    $q->pairs[] = ['left' => trim($pm[1]), 'right' => trim($pm[2])];
                    $q->type = 'matching';
                } else {
                    $q->warnings[] = "Could not read the pair \"$value\" (write Pair: left = right).";
                }
                break;
            case 'accept': case 'also accept':
                foreach (preg_split('/\s*[;|]\s*/u', $value) as $a) {
                    $a !== '' && $q->accepted[] = mb_substr($a, 0, 190);
                }
                break;
            case 'outcome':
                $q->outcome = mb_substr($value, 0, 190);
                break;
            case 'tolerance':
                $q->tolerance = abs((float) str_replace(',', '.', $value));
                break;
            case 'explanation':
                $this->section = 'explanation';
                $q->explanationHtml = $this->stripPrefix($html, self::M_PREFIX);
                break;
        }
    }

    private static function norm(string $s): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', mb_strtolower(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), " .\t");
    }

    /**
     * Answers from an answer key at the end of the paper, by question number, for questions whose
     * answer was not marked beside them.
     *
     * @param  array<int, string>  $key
     */
    private function applyKey(array $key): void
    {
        $used = 0;
        foreach ($this->questions as $q) {
            $answer = $key[$q->number] ?? null;
            if ($answer === null || array_filter($q->options, fn ($o) => $o['correct'])) {
                continue;
            }
            $answer = strtoupper(trim($answer));
            if ($q->options) {
                if (in_array($answer, ['T', 'TRUE', 'F', 'FALSE'], true) && $q->type === 'true_false') {
                    $isTrue = $answer[0] === 'T';
                    foreach ($q->options as &$o) {
                        $o['correct'] = (strtolower(strip_tags($o['html'])) === 'true') === $isTrue;
                    }
                    unset($o);
                } else {
                    preg_match_all('/[A-H]/', $answer, $mm);
                    foreach ($q->options as &$o) {
                        $o['correct'] = in_array($o['label'], $mm[0], true);
                    }
                    unset($o);
                    if (count($mm[0]) > 1 && $q->type === 'single_choice') {
                        $q->type = 'multiple_choice';
                    }
                }
                $q->warnings = array_values(array_filter($q->warnings, fn ($w) => ! str_starts_with($w, 'No correct option marked')));
                $used++;
            }
        }
        if ($used) {
            $this->warnings[] = "Answers for $used question".($used === 1 ? "" : "s")." were taken from the answer key at the end of the file.";
        }
    }

    private function finish(): void
    {
        $q = $this->current;
        if (! $q) {
            return;
        }
        $this->current = null;
        $this->closed = false;

        // Bare text with no number, options or Answer line: a heading or a note, not a question.
        if ($q->unnumbered && ! $q->options && ! $q->hasMeta && ! $q->pairs && $q->answerText === null) {
            $text = mb_strimwidth(trim(html_entity_decode(strip_tags($q->stemHtml))), 0, 80, '…');
            $this->warnings[] = $this->at($q->line).($this->questions ? "Skipped \"$text\": it has no options and no ANSWER line." : "Ignored text before the first question: \"$text\"");

            return;
        }

        // Old layout: every statement marked [T] / [F] is a multiple true/false question.
        $marked = count($q->options) >= 2 && count(array_filter($q->options, fn ($o) => ($o['tf'] ?? null) !== null)) === count($q->options);
        if ($marked && ($q->type === 'multi_true_false' || (! $q->typeGiven && ($q->answerText === null || self::truthList($q->answerText) === null) && ! array_filter($q->options, fn ($o) => $o['correct'])))) {
            $q->type = 'multi_true_false';
            if ($q->answerText === null || self::truthList($q->answerText) === null) {
                foreach ($q->options as &$o) {
                    [$o['correct'], $o['html']] = $o['tf'];
                }
                unset($o);
                $q->answerText = null;
            }
        }
        foreach ($q->options as &$o) {
            unset($o['tf']);
        }
        unset($o);

        // "TYPE: TF" with "ANSWER: TRUE" (no options written): the True / False options are made here.
        if (! $q->options && $q->answerText !== null && in_array($q->type, ['true_false', 'single_choice'], true)
            && ($q->type === 'true_false' || ! $q->typeGiven) && preg_match('/^(T|F|TRUE|FALSE|YES|NO)\.?$/i', trim($q->answerText))) {
            $true = in_array(strtoupper(trim($q->answerText, ". \t")), ['T', 'TRUE', 'YES'], true);
            $q->type = 'true_false';
            $q->options = [['label' => 'A', 'html' => 'True', 'correct' => $true], ['label' => 'B', 'html' => 'False', 'correct' => ! $true]];
            $q->answerText = null;
        }
        if ($q->type === 'true_false' && ! $q->options) {
            $q->options = [['label' => 'A', 'html' => 'True', 'correct' => false], ['label' => 'B', 'html' => 'False', 'correct' => false]];
            $q->warnings[] = 'No answer given: write ANSWER: TRUE or ANSWER: FALSE.';
        }

        // fill in the blanks: ___ in the text become [[1]], [[2]]…; "Answer: cell; nucleus|nuclei" gives each blank's answers
        if ($q->type === 'fill_blank') {
            $n = 0;
            $q->stemHtml = preg_replace_callback('/_{3,}|\[\[\d{1,2}\]\]/', function () use (&$n) {
                return '[['.(++$n).']]';
            }, $q->stemHtml);
            $parts = $q->answerText !== null ? array_map('trim', explode(';', $q->answerText)) : [];
            $q->blanks = array_map(fn ($p) => ['answers' => array_values(array_filter(array_map('trim', explode('|', $p))))], $parts);
            $q->answerText = null;
            $n === 0 && $q->warnings[] = 'No blanks found: write ___ (three or more underscores) where each answer goes.';
            $n !== count($q->blanks) && $n > 0 && $q->warnings[] = "The text has $n blanks but ".count($q->blanks).' answers were given (separate them with ;).';
            $this->questions[] = $q;

            return;
        }
        // put in order: the options as written are the right order
        if ($q->type === 'ordering') {
            foreach ($q->options as &$o) {
                $o['correct'] = true;
            }
            unset($o);
            count($q->options) < 2 && $q->warnings[] = 'Give at least two items to put in order.';
            $q->answerText = null;
            $this->questions[] = $q;

            return;
        }
        if ($q->type === 'file_upload') {
            $q->options = [];
            $q->answerText = null;
            $this->questions[] = $q;

            return;
        }

        // multiple true/false: the truth of every statement, by position or by letter
        if ($q->type === 'multi_true_false') {
            $truth = $q->answerText !== null ? self::truthList($q->answerText) : null;
            if ($truth === null && $q->answerText !== null && preg_match('/^[A-H](\s*[,;&]\s*[A-H])*$/i', $q->answerText)) {
                preg_match_all('/[A-H]/i', $q->answerText, $mm); // "Answer: A, C" = those are true, the rest false
                $truth = array_fill_keys(array_map('strtoupper', $mm[0]), true);
                foreach ($q->options as $o) {
                    $truth[$o['label']] ??= false;
                }
            }
            foreach ($q->options as $i => &$o) {
                if ($truth !== null) {
                    $o['correct'] = (bool) ($truth[$o['label']] ?? $truth[$i] ?? false);
                }
            }
            unset($o);
            if ($truth !== null && count($truth) !== count($q->options) && array_is_list($truth)) {
                $q->warnings[] = 'The answer gives '.count($truth).' true/false values for '.count($q->options).' statements.';
            }
            $q->answerText = null;
            count($q->options) < 2 && $q->warnings[] = 'A multiple true/false question needs at least two statements.';
            $this->questions[] = $q;

            return;
        }

        // "Answer: B" written before the options
        if ($q->answerText && $q->options && preg_match('/^[A-H](\s*[,;&]\s*[A-H])*$/i', $q->answerText)) {
            preg_match_all('/[A-H]/i', $q->answerText, $mm);
            foreach ($q->options as &$o) {
                $o['correct'] = $o['correct'] || in_array($o['label'], array_map('strtoupper', $mm[0]), true);
            }
            unset($o);
            $q->answerText = null;
        }

        if ($q->pairs) {
            $q->type = 'matching';
            if (count($q->pairs) < 2) {
                $q->warnings[] = 'A matching question needs at least two pairs.';
            }
        }

        // "Answer: Abuja": the option with that text
        if ($q->answerText !== null && $q->options && ! array_filter($q->options, fn ($o) => $o['correct'])) {
            $want = self::norm($q->answerText);
            foreach ($q->options as &$o) {
                if ($want !== '' && self::norm(strip_tags($o['html'])) === $want) {
                    $o['correct'] = true;
                    $q->answerText = null;
                }
            }
            unset($o);
        }
        // no answer written, but one option is in bold, underlined or highlighted (a common way to mark it)
        if ($q->answerText === null && count($q->options) >= 2 && ! array_filter($q->options, fn ($o) => $o['correct'])) {
            $marked = array_keys(array_filter($q->options, fn ($o) => (bool) preg_match('/^\s*<(strong|b|u|mark)\b[^>]*>.*<\/\1>\s*$/su', $o['html'])));
            if (count($marked) >= 1 && count($marked) < count($q->options)) {
                foreach ($marked as $i) {
                    $q->options[$i]['correct'] = true;
                }
                $q->warnings[] = 'The correct answer was taken from the option in bold, underline or highlight. Check it.';
            }
        }

        $correct = count(array_filter($q->options, fn ($o) => $o['correct']));
        $labels = array_map(fn ($o) => strtolower(strip_tags($o['html'])), $q->options);

        if ($q->type === 'matching') {
            // pairs, no lettered options
        } elseif ($q->options) {
            if (! in_array($q->type, ['single_choice', 'multiple_choice', 'true_false'], true)) {
                $q->type = 'single_choice';
            }
            if (count($q->options) === 2 && in_array('true', $labels, true) && in_array('false', $labels, true)) {
                $q->type = 'true_false';
            } elseif ($correct > 1) {
                $q->type = 'multiple_choice';
            }
            if (count($q->options) < 2) {
                $q->warnings[] = 'Only one option found.';
            }
            if ($correct === 0) {
                $q->warnings[] = 'No correct option marked (use * before the option or an "Answer:" line).';
            }
            if (count(array_unique(array_column($q->options, 'label'))) !== count($q->options)) {
                $q->warnings[] = 'Two options share the same letter.';
            }
        } elseif ($q->answerText !== null && is_numeric(str_replace(',', '.', $q->answerText))) {
            $q->type = 'numeric';
        } elseif (! in_array($q->type, ['essay', 'short_answer'], true)) {
            $q->type = $q->answerText !== null || $q->accepted ? 'short_answer' : 'essay';
        }
        if ($q->type === 'short_answer' && $q->answerText !== null) {
            array_unshift($q->accepted, mb_substr($q->answerText, 0, 190));
        }

        if (trim(strip_tags($q->stemHtml, '<img><table>')) === '') {
            $q->warnings[] = 'The question text is empty.';
        }

        $this->questions[] = $q;
    }

    /**
     * "TFTFT", "T F T", "A-T, B-F", "A=True; B=False" → truth by position (list) or by letter (map); null if not that form.
     *
     * @return array<int|string, bool>|null
     */
    public static function truthList(string $value): ?array
    {
        $v = trim($value);
        if (preg_match('/^([TF]\s*,?\s*){2,8}$/i', $v)) {
            preg_match_all('/[TF]/i', $v, $m);

            return array_map(fn ($c) => strtoupper($c) === 'T', $m[0]);
        }
        if (preg_match_all('/([A-H])\s*[-=:)]\s*(T(?:rue)?|F(?:alse)?)\b/i', $v, $m, PREG_SET_ORDER) && count($m) >= 2) {
            $out = [];
            foreach ($m as $x) {
                $out[strtoupper($x[1])] = strtoupper($x[2][0]) === 'T';
            }

            return $out;
        }

        return null;
    }

    private function html(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->textContent, ENT_QUOTES, 'UTF-8');
        }
        $tag = strtolower($node->nodeName);
        if (in_array($tag, ['table', 'img', 'figure'], true)) {
            return $node->ownerDocument->saveHTML($node);
        }
        // For list items, keep only inline content (nested lists are handled as blocks)
        $out = '';
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['ol', 'ul'], true)) {
                continue;
            }
            if ($tag === 'li' && $c instanceof DOMElement && strtolower($c->tagName) === 'p') {
                $out .= $this->html($c).' ';

                continue;
            }
            $out .= $node->ownerDocument->saveHTML($c);
        }

        return trim($out);
    }

    private function wrapBlock(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        return preg_match('/^<(table|figure|img)\b/i', $html) ? $html : '<p>'.$html.'</p>';
    }

    /** Remove a text prefix such as "12. " or "B) " that may sit behind opening tags. */
    private function stripPrefix(string $html, string $prefix): string
    {
        $re = '/^((?:\s|<[^>]+>)*)'.$prefix.'/iu';

        return preg_replace($re, '$1', $html, 1) ?? $html;
    }

    /** @return array{string,bool} html without a trailing/leading "*", and whether it was there */
    private function stripStar(string $html): array
    {
        $found = false;
        $html = preg_replace_callback('/^((?:\s|<[^>]+>)*)\*\s*/u', function ($m) use (&$found) {
            $found = true;

            return $m[1];
        }, $html, 1);
        $html = preg_replace_callback('/\s*\*((?:\s|<\/[^>]+>)*)$/u', function ($m) use (&$found) {
            $found = true;

            return $m[1];
        }, $html, 1);

        return [trim($html), $found];
    }
}
