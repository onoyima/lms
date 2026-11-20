<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One request to the configured AI, with text and pictures. Works with Claude (Anthropic Messages API)
 * and any OpenAI-compatible chat API. Retries briefly when the service is busy.
 */
class AiClient
{
    /** @param  array{provider: string, base_url: string, model: string, key: string}|null  $settings */
    public function __construct(private ?array $settings = null) {}

    public static function forTenant(): self
    {
        $s = AiSettings::resolve();
        if (! $s) {
            throw new RuntimeException('AI is not set up for this institution. An administrator can add it under Settings › AI.');
        }

        return new self($s);
    }

    /**
     * @param  list<array{text?: string, image?: string, mime?: string}>  $parts  text, or image bytes with their type
     * @return array{text: string, input_tokens: int|null, output_tokens: int|null}
     */
    public function ask(string $system, array $parts, int $maxTokens = 8000): array
    {
        $s = $this->settings ?? throw new RuntimeException('AI is not set up.');
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $res = $s['provider'] === 'anthropic' ? $this->anthropic($s, $system, $parts, $maxTokens) : $this->openai($s, $system, $parts, $maxTokens);
            } catch (ConnectionException $e) {
                if ($attempt < 3) {
                    sleep(2 * $attempt);

                    continue;
                }
                throw new RuntimeException('The AI service could not be reached ('.$s['base_url'].'). Check the internet connection or the address in Settings › AI.');
            }
            if (in_array($res->status(), [429, 500, 502, 503, 504, 529], true) && $attempt < 3) {
                sleep(3 * $attempt);

                continue;
            }
            if (! $res->successful()) {
                $msg = (string) ($res->json('error.message') ?? $res->json('error') ?? $res->body());
                throw new RuntimeException('The AI service refused the request ('.$res->status().'): '.mb_substr($msg, 0, 300));
            }

            return $s['provider'] === 'anthropic'
                ? ['text' => collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode(''), 'input_tokens' => $res->json('usage.input_tokens'), 'output_tokens' => $res->json('usage.output_tokens')]
                : ['text' => (string) $res->json('choices.0.message.content', ''), 'input_tokens' => $res->json('usage.prompt_tokens'), 'output_tokens' => $res->json('usage.completion_tokens')];
        }
    }

    private function anthropic(array $s, string $system, array $parts, int $maxTokens): \Illuminate\Http\Client\Response
    {
        $content = array_map(fn ($p) => isset($p['image'])
            ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $p['mime'] ?? 'image/png', 'data' => base64_encode($p['image'])]]
            : ['type' => 'text', 'text' => $p['text']], $parts);

        return Http::timeout(300)->connectTimeout(15)->withHeaders(['x-api-key' => $s['key'], 'anthropic-version' => '2023-06-01'])
            ->post($s['base_url'].'/v1/messages', ['model' => $s['model'], 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]]]);
    }

    private function openai(array $s, string $system, array $parts, int $maxTokens): \Illuminate\Http\Client\Response
    {
        $content = array_map(fn ($p) => isset($p['image'])
            ? ['type' => 'image_url', 'image_url' => ['url' => 'data:'.($p['mime'] ?? 'image/png').';base64,'.base64_encode($p['image'])]]
            : ['type' => 'text', 'text' => $p['text']], $parts);

        return Http::timeout(300)->connectTimeout(15)->withToken($s['key'])
            ->post($s['base_url'].'/chat/completions', ['model' => $s['model'], 'max_tokens' => $maxTokens, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $content]]]);
    }

    /** The first JSON object or array in a reply (models sometimes wrap it in a code block or a sentence). */
    public static function json(string $text): ?array
    {
        $t = trim($text);
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $t, $m)) {
            $t = trim($m[1]);
        }
        $start = strcspn($t, '{[');
        $t = substr($t, $start);
        $decoded = json_decode($t, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // trailing words after the JSON
        for ($end = strlen($t); $end > 0; $end = strrpos(substr($t, 0, $end - 1), '}') ?: 0) {
            $decoded = json_decode(substr($t, 0, $end), true);
            if (is_array($decoded)) {
                return $decoded;
            }
            if ($end <= 1) {
                break;
            }
        }

        return null;
    }
}
