<?php

namespace App\Services\Lms;

use App\Models\AssignmentSubmission;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiSettings;

/**
 * How likely a submission's writing is to have come from an AI tool, as a score from 0 to 100.
 *
 * Always measured here, with no outside service: how evenly sized the sentences and paragraphs
 * are, how often stock AI phrases and transition openers appear, and similar habits. When the
 * institution has set up AI (Settings › AI, feature "Check writing for AI"), the model also reads
 * the text and its judgement counts for most of the score.
 *
 * No detector is proof. The score tells a lecturer where to look; a person decides.
 */
class WritingCheck
{
    public const MIN_WORDS = 120;

    /** Phrases AI writing tools use far more than students do. */
    private const PHRASES = [
        'delve', 'delves', 'delving', 'tapestry', 'it is important to note', 'it is worth noting', 'plays a crucial role', 'plays a vital role',
        'plays a pivotal role', 'in today\'s', 'ever-evolving', 'ever-changing', 'fast-paced world', 'a testament to', 'navigate the complexities',
        'navigating the', 'in the realm of', 'the realm of', 'multifaceted', 'underscores', 'underscore the', 'foster', 'fostering', 'holistic',
        'paramount', 'seamless', 'seamlessly', 'leverage', 'leveraging', 'robust', 'nuanced', 'intricate', 'intricacies', 'pivotal', 'landscape of',
        'serves as a', 'a myriad of', 'myriad', 'shed light on', 'sheds light', 'in conclusion', 'in summary', 'to sum up', 'overall,',
        'embark', 'embarking', 'unlock', 'unlocking', 'harness', 'harnessing', 'cutting-edge', 'game-changer', 'transformative', 'meticulous',
        'meticulously', 'comprehensive understanding', 'crucial', 'vital role', 'not only', 'but also', 'whether you\'re', 'when it comes to',
        'stands as', 'bustling', 'vibrant', 'resonate', 'resonates', 'commendable', 'notably', 'furthermore', 'moreover', 'additionally',
    ];

    private const OPENERS = ['furthermore', 'moreover', 'additionally', 'in addition', 'however', 'overall', 'in conclusion', 'ultimately', 'consequently',
        'therefore', 'thus', 'firstly', 'secondly', 'lastly', 'finally', 'importantly', 'notably', 'similarly', 'conversely', 'in essence'];

    /** @return array{score: float|null, signals: list<array{label: string, level: string}>, model: string|null} */
    public function measure(string $text): array
    {
        $words = DocumentText::words($text);
        if ($words < self::MIN_WORDS) {
            return ['score' => null, 'signals' => [], 'model' => null];
        }
        $local = $this->local($text, $words);
        $score = $local['score'];
        $signals = $local['signals'];
        $model = null;
        if (AiSettings::enabled('check_writing')) {
            try {
                $reply = AiClient::forTenant()->ask(
                    'You review student coursework for signs that it was written by an AI tool (ChatGPT, Claude, Gemini and similar) rather than by the student. Judge the writing itself: generic phrasing, even rhythm, no personal voice or specific detail, confident filler, list-like structure. Students writing in a second language can sound formal; that alone is not a sign. Reply with JSON only: {"ai_likelihood": number 0-100, "reasons": ["short reason", ...] (at most 4)}',
                    [['text' => mb_substr($text, 0, 24000)]], 600);
                $json = AiClient::json($reply['text']);
                if (is_array($json) && is_numeric($json['ai_likelihood'] ?? null)) {
                    $m = max(0, min(100, (float) $json['ai_likelihood']));
                    $score = round(0.7 * $m + 0.3 * $score, 1);
                    $model = AiSettings::resolve()['model'] ?? 'AI';
                    foreach (array_slice((array) ($json['reasons'] ?? []), 0, 4) as $r) {
                        if (is_string($r) && $r !== '') {
                            $signals[] = ['label' => mb_substr($r, 0, 200), 'level' => $m >= 60 ? 'high' : ($m >= 30 ? 'some' : 'low'), 'source' => 'ai'];
                        }
                    }
                }
            } catch (\Throwable) {
                // the local measure stands on its own when the AI service is unavailable
            }
        }

        return ['score' => round($score, 1), 'signals' => $signals, 'model' => $model];
    }

    public function check(AssignmentSubmission $s, string $text): ?float
    {
        $r = $this->measure($text);
        $s->forceFill(['ai_score' => $r['score'], 'ai_signals' => ['signals' => $r['signals'], 'model' => $r['model']], 'ai_checked_at' => now()])->save();

        return $r['score'];
    }

    public static function band(?float $score): ?string
    {
        return $score === null ? null : ($score >= 60 ? 'high' : ($score >= 30 ? 'some' : 'low'));
    }

    /** @return array{score: float, signals: list<array{label: string, level: string}>} */
    private function local(string $text, int $words): array
    {
        $clean = preg_replace('/\s+/u', ' ', $text);
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+(?=[\p{Lu}"“(])/u', $clean)), fn ($x) => DocumentText::words($x) >= 3));
        $lens = array_map([DocumentText::class, 'words'], $sentences);
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n|\n(?=\s*[\p{Lu}•\-\d])/u', $text)), fn ($p) => DocumentText::words($p) >= 15));
        $plens = array_map([DocumentText::class, 'words'], $paragraphs);
        $lower = ' '.mb_strtolower($clean).' ';

        $cv = fn (array $xs) => count($xs) > 1 && array_sum($xs) > 0 ? sqrt(array_sum(array_map(fn ($x) => ($x - array_sum($xs) / count($xs)) ** 2, $xs)) / count($xs)) / (array_sum($xs) / count($xs)) : null;
        $clamp = fn (float $x) => max(0.0, min(1.0, $x));

        $parts = [];
        $signals = [];

        // 1. sentence rhythm: people mix short and long sentences, AI tools keep them even
        $scv = $cv($lens);
        if ($scv !== null && count($lens) >= 5) {
            $v = $clamp((0.62 - $scv) / 0.32);
            $parts[] = [$v, 0.3];
            if ($v >= 0.5) {
                $signals[] = ['label' => 'Sentences are very even in length', 'level' => $v >= 0.8 ? 'high' : 'some'];
            }
        }
        // 2. stock phrases per 1000 words
        $hits = 0;
        $found = [];
        foreach (self::PHRASES as $p) {
            $n = preg_match_all('/(?<![\p{L}])'.preg_quote($p, '/').'(?![\p{L}])/u', $lower);
            if ($n) {
                $hits += $n;
                $found[$p] = $n;
            }
        }
        $rate = $hits / $words * 1000;
        $v = $clamp(($rate - 2) / 10);
        $parts[] = [$v, 0.3];
        if ($v >= 0.3) {
            arsort($found);
            $signals[] = ['label' => 'Uses phrases common in AI writing ('.implode(', ', array_map(fn ($p) => '"'.rtrim($p, ',').'"', array_slice(array_keys($found), 0, 4))).')', 'level' => $v >= 0.7 ? 'high' : 'some'];
        }
        // 3. sentences opening with a transition word
        if (count($sentences) >= 5) {
            $open = count(array_filter($sentences, function ($x) {
                $x = mb_strtolower($x);
                foreach (self::OPENERS as $o) {
                    if (str_starts_with($x, $o.',') || str_starts_with($x, $o.' ')) {
                        return true;
                    }
                }

                return false;
            }));
            $v = $clamp(($open / count($sentences) - 0.06) / 0.2);
            $parts[] = [$v, 0.2];
            if ($v >= 0.5) {
                $signals[] = ['label' => 'Many sentences start with "Furthermore", "Moreover", "Additionally" and the like', 'level' => $v >= 0.8 ? 'high' : 'some'];
            }
        }
        // 4. paragraphs of near-equal size
        $pcv = $cv($plens);
        if ($pcv !== null && count($plens) >= 4) {
            $v = $clamp((0.45 - $pcv) / 0.3);
            $parts[] = [$v, 0.1];
            if ($v >= 0.6) {
                $signals[] = ['label' => 'Paragraphs are almost all the same size', 'level' => 'some'];
            }
        }
        // 5. long dashes, which students rarely type
        $dashes = preg_match_all('/—|\s–\s/u', $clean) / $words * 1000;
        $v = $clamp($dashes / 6);
        $parts[] = [$v, 0.1];
        if ($v >= 0.5) {
            $signals[] = ['label' => 'Frequent long dashes (—)', 'level' => 'some'];
        }

        $weight = array_sum(array_column($parts, 1));
        $score = $weight > 0 ? array_sum(array_map(fn ($p) => $p[0] * $p[1], $parts)) / $weight * 100 : 0;
        if (! $signals) {
            $signals[] = ['label' => 'No strong signs of AI writing in the style', 'level' => 'low'];
        }

        return ['score' => round($score, 1), 'signals' => $signals];
    }
}
