<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Suggests a mark for a written answer from the question, its mark and the marking guide. The
 * suggestion is stored on its own (ai_mark_suggestions) and shown to the examiner, who decides the
 * mark. Candidates are anonymous to the AI: it sees only the question, guide and answer.
 */
class MarkSuggester
{
    public function __construct(private ?AiClient $client = null) {}

    public static function instructions(): string
    {
        return <<<'TXT'
You help a university examiner mark written exam answers. Mark strictly against the marking guide when one is given; otherwise against what a correct, complete answer at university level must contain. Give partial credit point by point. Ignore spelling and grammar unless the guide says otherwise. Do not reward length. Maths may be in LaTeX.
Reply with JSON only: {"score": number (multiple of 0.5, from 0 to the full mark), "points": [{"point": "what was expected", "credit": number, "comment": "short"}], "reasons": "two or three sentences for the examiner"}
TXT;
    }

    /** @return array{score: float, out_of: float, reasons: string, points: array} */
    public function suggest(int $attemptId, int $questionId, int $userId): array
    {
        $row = DB::table('attempt_answers as aa')
            ->join('attempt_questions as aq', fn ($j) => $j->on('aq.attempt_id', '=', 'aa.attempt_id')->on('aq.question_id', '=', 'aa.question_id'))
            ->join('questions as q', 'q.id', '=', 'aa.question_id')
            ->where('aa.attempt_id', $attemptId)->where('aa.question_id', $questionId)
            ->first(['aa.response', 'aq.mark', 'q.stem_html', 'q.explanation_html']);
        if (! $row) {
            throw new RuntimeException('Answer not found.');
        }
        $text = trim((string) (json_decode((string) $row->response, true)['text'] ?? ''));
        $mark = (float) $row->mark;
        if ($text === '') {
            return $this->store($attemptId, $questionId, 0.0, $mark, 'The answer is empty.', [], null, $userId);
        }
        $client = $this->client ?? AiClient::forTenant();
        $reply = $client->ask(self::instructions(), [['text' => "Question (worth $mark marks):\n".strip_tags((string) $row->stem_html, '<sub><sup>')
            ."\n\nMarking guide:\n".($row->explanation_html ? strip_tags((string) $row->explanation_html, '<sub><sup>') : '(none given)')
            ."\n\nCandidate's answer:\n".mb_substr($text, 0, 30000)]], 2000);
        $json = AiClient::json($reply['text']);
        if (! is_array($json) || ! isset($json['score']) || ! is_numeric($json['score'])) {
            throw new RuntimeException('The AI did not give a usable mark. Try again.');
        }
        $score = max(0, min($mark, round(((float) $json['score']) * 2) / 2));

        return $this->store($attemptId, $questionId, $score, $mark, mb_substr((string) ($json['reasons'] ?? ''), 0, 2000),
            array_slice(array_values(array_filter((array) ($json['points'] ?? []), 'is_array')), 0, 20), AiSettings::resolve()['model'] ?? null, $userId);
    }

    private function store(int $attemptId, int $questionId, float $score, float $mark, string $reasons, array $points, ?string $model, int $userId): array
    {
        DB::table('ai_mark_suggestions')->updateOrInsert(['attempt_id' => $attemptId, 'question_id' => $questionId], [
            'tenant_id' => tenant_id(), 'score' => $score, 'out_of' => $mark, 'reasons' => $reasons, 'points' => json_encode($points),
            'model' => $model, 'requested_by' => $userId, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return ['score' => $score, 'out_of' => $mark, 'reasons' => $reasons, 'points' => $points];
    }
}
