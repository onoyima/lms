<?php

namespace App\Services\Ai;

use App\Models\Tenant;
use App\Support\Platform\PlatformSettings;
use Illuminate\Support\Facades\Crypt;

/**
 * Which AI a request uses. An institution can set its own provider and key; otherwise it uses the one
 * the platform provides (when the platform shares it). AI stays off until a key is set somewhere.
 *
 * Providers: "anthropic" (Claude) and "openai" (any OpenAI-compatible service: OpenAI, Azure OpenAI,
 * or a model on the institution's own network such as Ollama, LM Studio or vLLM).
 */
class AiSettings
{
    public const PROVIDERS = ['anthropic' => 'Claude (Anthropic)', 'openai' => 'OpenAI-compatible (OpenAI, Azure, or your own server)'];

    public const DEFAULT_MODEL = ['anthropic' => 'claude-sonnet-5-5', 'openai' => 'gpt-4o'];

    public const DEFAULT_URL = ['anthropic' => 'https://api.anthropic.com', 'openai' => 'https://api.openai.com/v1'];

    /** What AI is used for, each can be switched off by the institution. */
    public const FEATURES = ['read_papers' => 'Read uploaded papers into questions', 'write_questions' => 'Suggest new questions', 'suggest_marks' => 'Suggest marks for written answers'];

    /** @return array{provider: string, base_url: string, model: string, key: string, source: string}|null */
    public static function resolve(?Tenant $tenant = null): ?array
    {
        $tenant ??= tenant();
        $own = (array) data_get($tenant?->settings, 'ai', []);
        if (! empty($own['key'])) {
            return self::shape($own['provider'] ?? 'anthropic', $own['base_url'] ?? '', $own['model'] ?? '', self::open($own['key']), 'institution');
        }
        $p = PlatformSettings::all();
        if ($p['ai_key'] && $p['ai_shared'] && ($own['use_platform'] ?? true)) {
            return self::shape($p['ai_provider'], $p['ai_base_url'], $p['ai_model'], self::open($p['ai_key']), 'platform');
        }

        return null;
    }

    public static function enabled(string $feature, ?Tenant $tenant = null): bool
    {
        $tenant ??= tenant();

        // the platform can switch AI off for an institution (module ai_tools), whatever its own settings say
        if ($tenant && ! app(\App\Services\Modules\ModuleRegistry::class)->isEnabled($tenant, 'ai_tools')) {
            return false;
        }

        return self::resolve($tenant) !== null && (bool) data_get($tenant?->settings, "ai.features.$feature", true);
    }

    public static function seal(string $key): string
    {
        return Crypt::encryptString($key);
    }

    private static function open(string $sealed): string
    {
        try {
            return Crypt::decryptString($sealed);
        } catch (\Throwable) {
            return '';
        }
    }

    private static function shape(string $provider, string $url, string $model, string $key, string $source): ?array
    {
        if ($key === '') {
            return null;
        }
        $provider = array_key_exists($provider, self::PROVIDERS) ? $provider : 'anthropic';

        return [
            'provider' => $provider,
            'base_url' => rtrim($url ?: self::DEFAULT_URL[$provider], '/'),
            'model' => $model ?: self::DEFAULT_MODEL[$provider],
            'key' => $key,
            'source' => $source,
        ];
    }
}
