<?php

namespace App\Services\Questions\Import;

class ParsedQuestion
{
    public string $type = 'single_choice';

    public string $stemHtml = '';

    /** @var list<array{label:string, html:string, correct:bool}> */
    public array $options = [];

    public ?string $explanationHtml = null;

    public ?string $answerText = null;

    public float $mark = 1;

    public ?string $topic = null;

    public ?string $difficulty = null;

    public ?string $outcome = null;

    public ?float $tolerance = null;

    /** @var list<array{left:string, right:string}> matching pairs */
    public array $pairs = [];

    /** @var list<array{answers: list<string>}> fill-in-the-blank answers, by blank */
    public array $blanks = [];

    /** @var list<string> accepted short answers */
    public array $accepted = [];

    /** @var list<string> */
    public array $warnings = [];

    /** Line of the file the question starts on (text and CSV files), for messages. */
    public ?int $line = null;

    /** Picture file named on an "IMAGE:" line, found in the pictures uploaded with the file. */
    public ?string $image = null;

    /** "all" (all or nothing) or "partial", from a "SCORING:" line. */
    public ?string $scoring = null;

    /** The type was given on a Type: line (so it is not guessed). */
    public bool $typeGiven = false;

    /** Any Answer/Mark/Topic/Type line was given (bare text with none of these is a heading, not a question). */
    public bool $hasMeta = false;

    /** Started without a number (old system's plain "Aiken" layout). */
    public bool $unnumbered = false;

    public function __construct(public int $number) {}

    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'type' => $this->type,
            'stem_html' => $this->stemHtml,
            'options' => $this->options,
            'explanation_html' => $this->explanationHtml,
            'answer_text' => $this->answerText,
            'mark' => $this->mark,
            'topic' => $this->topic,
            'difficulty' => $this->difficulty,
            'outcome' => $this->outcome,
            'tolerance' => $this->tolerance,
            'pairs' => $this->pairs,
            'accepted' => $this->accepted,
            'blanks' => $this->blanks,
            'warnings' => $this->warnings,
            'line' => $this->line,
            'image' => $this->image,
            'scoring' => $this->scoring,
        ];
    }
}
