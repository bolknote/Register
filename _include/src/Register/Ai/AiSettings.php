<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Ai;

use S2\Cms\Config\DynamicConfigProvider;

final readonly class AiSettings
{
    public const string PROVIDER_CONFIG_KEY = 'REGISTER_AI_PROVIDER';

    public const string API_KEY_CONFIG_KEY = 'REGISTER_AI_API_KEY';

    public const string MODEL_CONFIG_KEY = 'REGISTER_AI_MODEL';

    public const string PROVIDER_DISABLED = 'disabled';

    public const string PROVIDER_GEMINI = 'gemini';

    public const string PROVIDER_GROQ = 'groq';

    private const array DEFAULT_MODELS = [
        self::PROVIDER_GEMINI => 'gemini-3.5-flash-lite',
        self::PROVIDER_GROQ   => 'openai/gpt-oss-20b',
    ];

    public function __construct(private DynamicConfigProvider $configProvider)
    {
    }

    public function provider(): string
    {
        $provider = (string)$this->configProvider->get(self::PROVIDER_CONFIG_KEY);

        return self::isSupportedProvider($provider) ? $provider : self::PROVIDER_DISABLED;
    }

    public function apiKey(): string
    {
        return trim((string)$this->configProvider->get(self::API_KEY_CONFIG_KEY));
    }

    public function model(): string
    {
        $model = trim((string)$this->configProvider->get(self::MODEL_CONFIG_KEY));
        if ($model !== '') {
            return $model;
        }

        return self::DEFAULT_MODELS[$this->provider()] ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->provider() !== self::PROVIDER_DISABLED
            && $this->apiKey() !== ''
            && $this->model() !== '';
    }

    public static function isSupportedProvider(string $provider): bool
    {
        return \in_array($provider, [
            self::PROVIDER_DISABLED,
            self::PROVIDER_GEMINI,
            self::PROVIDER_GROQ,
        ], true);
    }
}
