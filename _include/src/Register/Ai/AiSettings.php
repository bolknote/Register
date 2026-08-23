<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Ai;

use Register\Core\Config\DynamicConfigProvider;

final readonly class AiSettings
{
    public const string PROVIDER_CONFIG_KEY = 'REGISTER_AI_PROVIDER';

    public const string API_KEY_CONFIG_KEY = 'REGISTER_AI_API_KEY';

    public const string MODEL_CONFIG_KEY = 'REGISTER_AI_MODEL';

    public const string FOLDER_ID_CONFIG_KEY = 'REGISTER_AI_FOLDER_ID';

    public const string CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY = 'REGISTER_AI_CLOUDFLARE_ACCOUNT_ID';

    public const string GIGACHAT_SCOPE_CONFIG_KEY = 'REGISTER_AI_GIGACHAT_SCOPE';

    public const string AUTO_ALT_CONFIG_KEY = 'REGISTER_AI_AUTO_ALT';

    public const string AUTO_METADATA_CONFIG_KEY = 'REGISTER_AI_AUTO_METADATA';

    public const string PROVIDER_DISABLED = 'disabled';

    public const string PROVIDER_GEMINI = 'gemini';

    public const string PROVIDER_GROQ = 'groq';

    public const string PROVIDER_OPENROUTER = 'openrouter';

    public const string PROVIDER_MISTRAL = 'mistral';

    public const string PROVIDER_CLOUDFLARE = 'cloudflare';

    public const string PROVIDER_YANDEX = 'yandex';

    public const string PROVIDER_GIGACHAT = 'gigachat';

    public const string GIGACHAT_SCOPE_PERSONAL = 'GIGACHAT_API_PERS';

    public const string GIGACHAT_SCOPE_BUSINESS = 'GIGACHAT_API_B2B';

    public const string GIGACHAT_SCOPE_CORPORATE = 'GIGACHAT_API_CORP';

    private const array DEFAULT_MODELS = [
        self::PROVIDER_GEMINI => 'gemini-3.5-flash-lite',
        self::PROVIDER_GROQ   => 'openai/gpt-oss-20b',
        self::PROVIDER_OPENROUTER => 'openrouter/free',
        self::PROVIDER_MISTRAL => 'mistral-small-latest',
        self::PROVIDER_CLOUDFLARE => '@cf/google/gemma-4-26b-a4b-it',
        self::PROVIDER_YANDEX => 'yandexgpt-5-lite',
        self::PROVIDER_GIGACHAT => 'GigaChat-2-Pro',
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

        return self::defaultModelForProvider($this->provider());
    }

    public function autoAltEnabled(): bool
    {
        // Older installations are upgraded by SchemaManager. Keeping the fallback enabled
        // also makes the feature safe during the short interval before that upgrade runs.
        return $this->booleanSetting(self::AUTO_ALT_CONFIG_KEY, true);
    }

    public function autoMetadataEnabled(): bool
    {
        // Text must not be sent to an external provider merely because an older installation
        // has not reached the config-default bootstrap yet.
        return $this->booleanSetting(self::AUTO_METADATA_CONFIG_KEY, false);
    }

    public function supportsImageInput(): bool
    {
        return self::supportsImageInputFor($this->provider(), $this->model());
    }

    public function autoAltAvailable(): bool
    {
        return $this->isConfigured() && $this->autoAltEnabled() && $this->supportsImageInput();
    }

    public function folderId(): string
    {
        return trim((string)$this->configProvider->get(self::FOLDER_ID_CONFIG_KEY));
    }

    public function cloudflareAccountId(): string
    {
        return trim((string)$this->configProvider->get(self::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY));
    }

    public function gigaChatScope(): string
    {
        $scope = trim((string)$this->configProvider->get(self::GIGACHAT_SCOPE_CONFIG_KEY));

        return self::isSupportedGigaChatScope($scope) ? $scope : self::GIGACHAT_SCOPE_PERSONAL;
    }

    public function isConfigured(): bool
    {
        if ($this->apiKey() === '' || $this->model() === '') {
            return false;
        }

        return match ($this->provider()) {
            self::PROVIDER_GEMINI,
            self::PROVIDER_GROQ,
            self::PROVIDER_OPENROUTER,
            self::PROVIDER_MISTRAL,
            self::PROVIDER_GIGACHAT => true,
            self::PROVIDER_CLOUDFLARE => $this->cloudflareAccountId() !== '',
            self::PROVIDER_YANDEX => $this->folderId() !== '',
            default => false,
        };
    }

    public static function isSupportedProvider(string $provider): bool
    {
        return \in_array($provider, [
            self::PROVIDER_DISABLED,
            self::PROVIDER_GEMINI,
            self::PROVIDER_GROQ,
            self::PROVIDER_OPENROUTER,
            self::PROVIDER_MISTRAL,
            self::PROVIDER_CLOUDFLARE,
            self::PROVIDER_YANDEX,
            self::PROVIDER_GIGACHAT,
        ], true);
    }

    public static function isSupportedGigaChatScope(string $scope): bool
    {
        return \in_array($scope, [
            self::GIGACHAT_SCOPE_PERSONAL,
            self::GIGACHAT_SCOPE_BUSINESS,
            self::GIGACHAT_SCOPE_CORPORATE,
        ], true);
    }

    public static function defaultModelForProvider(string $provider): string
    {
        return self::DEFAULT_MODELS[$provider] ?? '';
    }

    private function booleanSetting(string $name, bool $fallback): bool
    {
        try {
            $value = $this->configProvider->get($name);
        } catch (\LogicException) {
            return $fallback;
        }

        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The providers do not expose one common capabilities endpoint. Keep this list deliberately
     * conservative: an unknown custom model is treated as text-only instead of receiving an image.
     */
    public static function supportsImageInputFor(string $provider, string $model): bool
    {
        $model = strtolower(trim($model));
        if ($model === '' || str_contains($model, 'embedding')) {
            return false;
        }

        return match ($provider) {
            self::PROVIDER_GEMINI => str_starts_with($model, 'gemini-'),
            self::PROVIDER_GROQ => preg_match('/(?:vision|(?:^|\/)qwen[^\/]*-vl|llama-4)/', $model) === 1,
            self::PROVIDER_OPENROUTER => \in_array($model, ['openrouter/auto', 'openrouter/free'], true)
                || preg_match('/(?:gemini|claude-(?:3|4)|gpt-(?:4o|5)|llama-4|gemma-4|pixtral|vision|qwen[^\/]*-vl|mistral-(?:small|medium|large)|ministral)/', $model) === 1,
            self::PROVIDER_MISTRAL => preg_match('/(?:pixtral|ministral|mistral-(?:small|medium|large))/', $model) === 1,
            self::PROVIDER_CLOUDFLARE => preg_match('/(?:gemma-4|llama-4|llava|vision|qwen[^\/]*-vl)/', $model) === 1,
            self::PROVIDER_GIGACHAT => str_starts_with($model, 'gigachat'),
            default => false,
        };
    }
}
