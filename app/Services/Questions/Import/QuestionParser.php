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
 * Word's own numbered lists work too: a numbered list item starts a question and
 * a lettered (A, B, C) sub-list holds its options.
 */
class QuestionParser
{
    private const QUESTION_START = '/^\s*(?:Q(?:uestion)?\s*)?(\d{1,4})\s*[.)]\s+/iu';

    private const OPTION_START = '/^\s*(\*)?\s*\(?([A-Ha-h])[.)]\s+/u';

    private const META = '/^\s*(Answer|Ans|Correct(?:\s+answer)?|Mark|Marks|Score|Topic|Difficulty|Type|Explanation|Pair|Match|Accept|Also accept|Outcome|Tolerance)\s*[:\-]\s*(.*)$/isu';

    // The same prefixes, for removing them from the HTML (they can sit behind opening tags)
    private const Q_PREFIX = '(?:Q(?:uestion)?\s*)?\d{1,4}\s*[.)]\s+';

    private const O_PREFIX = '\*?\s*\(?[A-Ha-h][.)]\s+';

    private const M_PREFIX = '(?:Answer|Ans|Correct(?:\s+answer)?|Mark|Marks|Score|Topic|Difficulty|Type|Explanation|Pair|Match|Accept|Also accept|Outcome|Tolerance)\s*[:\-]\s*';

    /** @var list<ParsedQuestion> */
    private array $questions = [];

    private ?ParsedQuestion $current = null;

    private string $section = 'stem'; // stem, option, explanation

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

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);
        foreach ($this->blocks($body) as [$kind, $node, $extra]) {
            $this->consume($kind, $node, $extra);
        }
        $this->finish();

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
        $html = $this->html($node);
        if ($text === '' && ! preg_match('/<(img|table)\b/i', $html)) {
            return;
        }

        if (preg_match(self::QUESTION_START, $text, $m)) {
            $this->startQuestion((int) $m[1]);
            $this->appendStem($this->stripPrefix($html, self::Q_PREFIX));

            return;
        }

        if ($this->current && preg_match(self::OPTION_START, $text, $m) && $this->section !== 'explanation') {
            $html = $this->stripPrefix($html, self::O_PREFIX);
            [$html, $trailingStar] = $this->stripStar($html);
            $this->addOption(strtoupper($m[2]), $html, $m[1] === '*' || $trailingStar);

            return;
        }

        if ($this->current && preg_match(self::META, $text, $m)) {
            $this->applyMeta(strtolower($m[1]), trim($m[2]), $html);

            return;
        }

        if (! $this->current) {
            $this->warnings[] = 'Ignored text before the first question: "'.mb_strimwidth($text, 0, 80, '…').'"';

            return;
        }

        match ($this->section) {
            'stem' => $this->appendStem($html),
            'option' => $this->appendToLastOption($html),
            'explanation' => $this->current->explanationHtml .= $html,
        };
    }

    private function startQuestion(int $number): void
    {
        $this->finish();
        $this->current = new ParsedQuestion($number);
        $this->section = 'stem';
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
        $this->current->options[] = ['label' => $label, 'html' => trim($html), 'correct' => $correct];
    }

    private function appendToLastOption(string $html): void
    {
        $i = array_key_last($this->current->options);
        $this->current->options[$i]['html'] .= ' '.$html;
    }

    private function applyMeta(string $key, string $value, string $html): void
    {
        $q = $this->current;
        switch ($key) {
            case 'answer': case 'ans': case 'correct': case 'correct answer':
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
                $aliases = ['mcq' => 'single_choice', 'objective' => 'single_choice', 'multi' => 'multiple_choice', 'tf' => 'true_false', 'theory' => 'essay', 'short' => 'short_answer'];
                $q->type = $aliases[$v] ?? $v;
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

    private function finish(): void
    {
        $q = $this->current;
        if (! $q) {
            return;
        }
        $this->current = null;

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
