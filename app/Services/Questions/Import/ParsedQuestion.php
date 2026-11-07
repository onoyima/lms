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

    /** @var list<string> accepted short answers */
    public array $accepted = [];

    /** @var list<string> */
    public array $warnings = [];

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
            'warnings' => $this->warnings,
        ];
    }
}
