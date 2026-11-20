<?php

namespace App\Services\Ai;

use App\Models\Question;
use App\Models\QuestionImport;
use RuntimeException;

/**
 * Drafts new questions with AI from a topic (and, if given, the lecturer's own notes). The drafts go
 * to the same review page as an uploaded paper; nothing reaches the question bank until the lecturer
 * saves it, and saved questions are drafts that are moderated as usual.
 */
class QuestionWriter
{
    public function __construct(private ?AiClient $client = null, private ?PaperReader $reader = null) {}

    /** @return array{questions: list<array>, warnings: list<string>, usage: array{input: int, output: int}} */
    public function write(QuestionImport $import, array $brief): array
    {
        $client = $this->client ?? AiClient::forTenant();
        $types = array_values(array_intersect((array) ($brief['types'] ?? ['single_choice']), Question::TYPES)) ?: ['single_choice'];
        $count = max(1, min(40, (int) ($brief['count'] ?? 10)));
        $ask = "Write $count new examination questions.\n"
            .'Course: '.($brief['course'] ?? 'not given')."\n"
            .'Topic: '.($brief['topic'] ?? '')."\n"
            .'Level: '.($brief['level'] ?? 'undergraduate')."\n"
            .'Difficulty: '.($brief['difficulty'] ?? 'mixed')."\n"
            .'Question types to use: '.implode(', ', $types)."\n"
            .(! empty($brief['instructions']) ? 'Lecturer\'s instructions: '.$brief['instructions']."\n" : '')
            .(! empty($brief['notes']) ? "\nBase the questions only on these lecture notes:\n".mb_substr((string) $brief['notes'], 0, 60000)."\n" : '')
            ."\nEvery question must have exactly one defensible answer key (for objective types), plausible wrong options, and a short explanation of the answer in \"explanation\" (for essays: a marking guide with the points expected and their marks). Give \"mark\" for each. Do not copy questions from published exams.";
        $reply = $client->ask(PaperReader::instructions()."\n\nYou are now WRITING new questions, not reading a paper: there are no pages or figures; use the same JSON form.", [['text' => $ask]], 16000);
        $json = AiClient::json($reply['text']);
        if (! is_array($json) || ! isset($json['questions'])) {
            throw new RuntimeException('The AI did not answer in the expected form. Try again.');
        }
        $reader = $this->reader ?? new PaperReader($client);
        $questions = [];
        foreach (array_values(array_filter($json['questions'], 'is_array')) as $i => $q) {
            $q['number'] = $i + 1;
            unset($q['figures']);
            $questions[] = $reader->shapeQuestion($import, $q);
        }

        return ['questions' => $questions, 'warnings' => ['Written by AI: check every question, its answer and its explanation before saving.'],
            'usage' => ['input' => (int) $reply['input_tokens'], 'output' => (int) $reply['output_tokens']]];
    }
}
