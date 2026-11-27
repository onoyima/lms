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

    public function test_multiple_true_false_by_position_by_letter_or_by_star(): void
    {
        $qs = $this->parseText(
            "1. Concerning the heart:\nA. It has four chambers\nB. The left ventricle pumps to the lungs\nC. The SA node is the pacemaker\nD. Valves stop backflow\nType: MTF\nAnswer: TFTT\n\n"
            ."2. About insulin:\nA. It lowers blood glucose\nB. It is made by alpha cells\nC. It is a protein\nAnswer: A-T, B-F, C-T\n\n"
            ."3. Respiration:\n*A. Oxygen is used\nB. It happens only in the lungs\n*C. Carbon dioxide is released\nType: multiple true false"
        );
        $this->assertSame(['multi_true_false', 'multi_true_false', 'multi_true_false'], array_column($qs, 'type'));
        $this->assertSame([true, false, true, true], array_column($qs[0]['options'], 'correct'));
        $this->assertSame([true, false, true], array_column($qs[1]['options'], 'correct'));
        $this->assertSame([true, false, true], array_column($qs[2]['options'], 'correct'));
        $this->assertSame([], $qs[0]['warnings']);
    }

    /** The layouts lecturers actually type: each is read without retyping the paper. */
    public function test_everyday_layouts_are_understood(): void
    {
        $correct = fn (array $q) => implode('', array_map(fn ($o) => $o['correct'] ? $o['label'] : '', $q['options']));

        // options on one line, "(b)" answers
        $qs = $this->parseText("1) Capital of Nigeria?\n(a) Lagos (b) Abuja (c) Kano (d) Ibadan\nAns: (b)");
        $this->assertCount(4, $qs[0]['options']);
        $this->assertSame('B', $correct($qs[0]));

        // options after the question on the same line; "Ans." with a full stop
        $qs = $this->parseText("(1) Vitamin A is found in carrots. Which organ needs it? A. Eye  B. Ear  C. Nose  D. Liver\nAns. A");
        $this->assertStringContainsString('Vitamin A is found', $qs[0]['stem_html']);
        $this->assertSame('A', $correct($qs[0]));

        // "Question 1:" and "A: Lagos", answer written as the option's text
        $qs = $this->parseText("Question 1: Capital of Nigeria?\nA: Lagos\nB: Abuja\nC: Kano\nAnswer: Abuja\nQuestion 2: Noble gas?\nA - Neon\nB - Oxygen\nCorrect option: A");
        $this->assertCount(2, $qs);
        $this->assertSame(['B', 'A'], [$correct($qs[0]), $correct($qs[1])]);

        // numbers and letters without full stops, a trailing star
        $qs = $this->parseText("1 Capital of Nigeria?\na Lagos\nb Abuja*\nc Kano\n2 Noble gas?\na Neon*\nb Oxygen");
        $this->assertCount(2, $qs);
        $this->assertSame(['B', 'A'], [$correct($qs[0]), $correct($qs[1])]);

        // a stem line that starts with a number is not a new question
        $qs = $this->parseText("1. Which is right?\n2 moles of water weigh 36 g\nA. True\nB. False\nAnswer: A");
        $this->assertCount(1, $qs);
    }

    public function test_answer_key_at_the_end_of_the_paper(): void
    {
        $qs = $this->parseText("1. Prime?\nA. 4\nB. 7\n2. Metal?\nA. Sodium   B. Sulphur   C. Carbon\n\nAnswer Key\n1. B   2. A");
        $this->assertSame([false, true], array_column($qs[0]['options'], 'correct'));
        $this->assertSame([true, false, false], array_column($qs[1]['options'], 'correct'));

        // a bare list of "1. B" lines closing the paper
        $qs = $this->parseText("1. a?\nA. x\nB. y\n2. b?\nA. x\nB. y\n3. c?\nA. x\nB. y\n1. B\n2. A\n3. B");
        $this->assertCount(3, $qs);
        $this->assertSame([false, true], array_column($qs[2]['options'], 'correct'));
    }

    public function test_bold_option_marks_the_answer_when_none_is_written(): void
    {
        $r = (new QuestionParser)->parse('<p>1. Makes ATP?</p><p>A. Nucleus</p><p>B. <strong>Mitochondrion</strong></p><p>C. Ribosome</p>');
        $q = $r['questions'][0]->toArray();
        $this->assertSame([false, true, false], array_column($q['options'], 'correct'));
        $this->assertNotEmpty($q['warnings']); // the lecturer is asked to check it
    }

    public function test_a_table_with_one_question_per_row(): void
    {
        $html = '<table><tr><td>No</td><td>Question</td><td>A</td><td>B</td><td>Ans</td></tr><tr><td>1</td><td>Unit of force</td><td>Joule</td><td>Newton</td><td>B</td></tr></table>';
        $q = (new QuestionParser)->parse($html)['questions'][0]->toArray();
        $this->assertSame('Unit of force', strip_tags($q['stem_html']));
        $this->assertSame([false, true], array_column($q['options'], 'correct'));
    }

    public function test_old_word_and_rtf_text(): void
    {
        $this->assertSame("1. Hello\nA.\tx\n", \App\Services\Questions\Import\LegacyWordReader::clean("1. Hello\rA.\x07x\x07\x07"));
        $this->assertSame("Caf\u{e9} 1.\nA. \u{2014}", \App\Services\Questions\Import\LegacyWordReader::rtfText("{\\rtf1\\ansi{\\fonttbl{\\f0 Arial;}}Caf\\'e9 1.\\par A. \\emdash}"));
    }
}
