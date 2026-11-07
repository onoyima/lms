<?php

namespace Tests\Unit;

use App\Services\Questions\Import\DocumentConverter;
use App\Services\Questions\Import\QuestionParser;
use PHPUnit\Framework\TestCase;

class QuestionParserTest extends TestCase
{
    private function parseText(string $text): array
    {
        $html = (new DocumentConverter('pandoc-not-needed'))->textToHtml($text);

        return array_map(fn ($q) => $q->toArray(), (new QuestionParser)->parse($html)['questions']);
    }

    public function test_starred_option_and_chemistry_are_understood(): void
    {
        [$q] = $this->parseText("1. Balance it: \$\\ce{H2 + O2 -> H2O}\$\nA. one\n*B. \$\\ce{2H2O}\$\nC. three");

        $this->assertSame('single_choice', $q['type']);
        $this->assertStringContainsString('\(\ce{H2 + O2 -&gt; H2O}\)', $q['stem_html']);
        $this->assertSame(['A', 'B', 'C'], array_column($q['options'], 'label'));
        $this->assertSame([false, true, false], array_column($q['options'], 'correct'));
        $this->assertStringContainsString('\(\ce{2H2O}\)', $q['options'][1]['html']);
    }

    public function test_answer_lines_marks_topics_and_multiple_answers(): void
    {
        $qs = $this->parseText("Q1) Noble gases?\n(a) Neon\n(b) Nitrogen\n(c) Argon\nAnswer: A, C\nMark: 2\nTopic: Periodic table\n\n2. Pick one\nA. x\nB. y\nAns: B");

        $this->assertSame('multiple_choice', $qs[0]['type']);
        $this->assertSame([true, false, true], array_column($qs[0]['options'], 'correct'));
        $this->assertSame(2.0, $qs[0]['mark']);
        $this->assertSame('Periodic table', $qs[0]['topic']);
        $this->assertSame([false, true], array_column($qs[1]['options'], 'correct'));
    }

    public function test_numeric_essay_and_true_false(): void
    {
        $qs = $this->parseText("1. g to 1 d.p.\nAnswer: 9.8\n2. Explain osmosis.\n3. Water is wet.\nA. True\nB. False\nAnswer: A");

        $this->assertSame(['numeric', 'essay', 'true_false'], array_column($qs, 'type'));
        $this->assertSame('9.8', $qs[0]['answer_text']);
    }

    public function test_display_maths_and_explanations(): void
    {
        [$q] = $this->parseText("1. Evaluate \$\$\\int_0^1 x^2\\,dx\$\$\nA. 1/2\nB. 1/3 *\nExplanation: \$\\frac{x^3}{3}\$ from 0 to 1");

        $this->assertStringContainsString('<span class="math display">\[\int_0^1 x^2\,dx\]</span>', $q['stem_html']);
        $this->assertTrue($q['options'][1]['correct']);
        $this->assertStringContainsString('\(\frac{x^3}{3}\)', $q['explanation_html']);
    }

    public function test_problems_are_reported_not_hidden(): void
    {
        [$q] = $this->parseText("1. No answer marked\nA. x\nB. y");

        $this->assertContains('No correct option marked (use * before the option or an "Answer:" line).', $q['warnings']);
    }

    public function test_a_literal_dollar_is_not_maths(): void
    {
        [$q] = $this->parseText("1. A book costs \\$5 and a pen \\$2. Total?\nAnswer: 7");

        $this->assertStringNotContainsString('math', $q['stem_html']);
        $this->assertStringContainsString('$5', $q['stem_html']);
    }

    public function test_matching_pairs_accepted_answers_and_outcomes(): void
    {
        $qs = $this->parseText("1. Match the symbols\nPair: Na = Sodium\nPair: K -> Potassium\nOutcome: LO2\nMark: 2\n\n2. Symbol for gold?\nType: short_answer\nAccept: Au\nAlso accept: gold");

        $this->assertSame('matching', $qs[0]['type']);
        $this->assertSame('LO2', $qs[0]['outcome']);
        $this->assertCount(2, $qs[0]['pairs']);
        $this->assertSame('short_answer', $qs[1]['type']);
        $this->assertEqualsCanonicalizing(['Au', 'gold'], $qs[1]['accepted']);
    }
}
