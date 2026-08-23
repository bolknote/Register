<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Core\HttpClient\HttpClientInterface;
use Register\Core\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\Request;

/** OAuth 2.1/PKCE integration for Yandex and VK ID (VK, Mail.ru and OK). */
final readonly class PublicOAuthClient
{
    private const array VK_PROVIDERS = ['vk', 'mail_ru', 'ok_ru'];

    public function __construct(
        private HttpClientInterface   $httpClient,
        private PublicAuthSettings    $settings,
        private PublicAuthRepository  $repository,
        private UrlBuilder            $urlBuilder,
    ) {
    }

    public function authorizationUrl(string $provider, string $returnPath): string
    {
        $provider = $this->normalizeProvider($provider);
        if ($provider === 'yandex' && !$this->settings->yandexEnabled()) {
            throw new \RuntimeException('Yandex sign-in is not configured.');
        }

        if ($provider !== 'yandex' && !$this->settings->vkEnabled()) {
            throw new \RuntimeException('VK ID sign-in is not configured.');
        }

        $returnPath = PublicReturnPath::normalize($returnPath);
        $state = $this->randomUrlToken(32);
        $verifier = $this->randomUrlToken(48);
        $deviceId = $this->uuidV4();
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $this->repository->storeFlow($state, $provider, $verifier, $deviceId, $returnPath);

        if ($provider === 'yandex') {
            return 'https://oauth.yandex.ru/authorize?' . http_build_query([
                'response_type'         => 'code',
                'client_id'             => $this->settings->yandexClientId(),
                'redirect_uri'          => $this->callbackUrl('yandex'),
                'scope'                 => 'login:email login:info',
                'state'                 => $state,
                'code_challenge'        => $challenge,
                'code_challenge_method' => 'S256',
            ], '', '&', PHP_QUERY_RFC3986);
        }

        $query = [
            'response_type'         => 'code',
            'client_id'             => $this->settings->vkClientId(),
            'redirect_uri'          => $this->callbackUrl($provider),
            'scope'                 => 'email',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 's256',
            'device_id'             => $deviceId,
        ];
        if ($provider !== 'vk') {
            $query['provider'] = $provider;
        }

        return 'https://id.vk.ru/authorize?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(Request $request, string $provider): ExternalIdentity
    {
        $provider = $this->normalizeProvider($provider);
        $state = trim($request->query->getString('state'));
        $code = trim($request->query->getString('code'));
        if ($state === '' || $code === '' || $request->query->has('error')) {
            throw new \RuntimeException('The authentication provider did not return a usable authorization code.');
        }

        $flow = $this->repository->consumeFlow($state);
        if (!\is_array($flow) || !hash_equals((string)$flow['provider'], $provider)) {
            throw new \RuntimeException('The authentication attempt has expired or has already been used.');
        }

        return $provider === 'yandex'
            ? $this->exchangeYandex($code, $flow)
            : $this->exchangeVk($request, $code, $provider, $flow);
    }

    /** @param array<string, mixed> $flow */
    private function exchangeYandex(string $code, array $flow): ExternalIdentity
    {
        $token = $this->requestJson(
            'POST',
            'https://oauth.yandex.ru/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => $this->settings->yandexClientId(),
                'client_secret' => $this->settings->yandexClientSecret(),
                'redirect_uri'  => $this->callbackUrl('yandex'),
                'code_verifier' => (string)$flow['code_verifier'],
            ], '', '&', PHP_QUERY_RFC3986),
        );
        $accessToken = \is_string($token['access_token'] ?? null) ? $token['access_token'] : '';
        if ($accessToken === '') {
            throw new \RuntimeException('Yandex did not issue an access token.');
        }

        $profile = $this->requestJson(
            'GET',
            'https://login.yandex.ru/info?format=json',
            ['Authorization' => 'OAuth ' . $accessToken],
        );
        $subject = (string)($profile['id'] ?? '');
        $email = (string)($profile['default_email'] ?? '');
        $name = trim((string)($profile['real_name'] ?? $profile['display_name'] ?? $profile['login'] ?? ''));
        $avatar = '';
        if (\is_string($profile['default_avatar_id'] ?? null) && $profile['default_avatar_id'] !== '') {
            $avatar = 'https://avatars.yandex.net/get-yapic/' . rawurlencode($profile['default_avatar_id']) . '/islands-200';
        }

        return $this->identity('yandex', $subject, $email, $name, $avatar, $flow);
    }

    /** @param array<string, mixed> $flow */
    private function exchangeVk(Request $request, string $code, string $provider, array $flow): ExternalIdentity
    {
        $expectedDeviceId = (string)$flow['device_id'];
        $returnedDeviceId = trim($request->query->getString('device_id'));
        if ($returnedDeviceId !== '' && !hash_equals($expectedDeviceId, $returnedDeviceId)) {
            throw new \RuntimeException('VK ID returned a mismatched device identifier.');
        }

        $deviceId = $returnedDeviceId !== '' ? $returnedDeviceId : $expectedDeviceId;
        $token = $this->requestJson(
            'POST',
            'https://id.vk.ru/oauth2/auth?' . http_build_query([
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->callbackUrl($provider),
                'client_id'     => $this->settings->vkClientId(),
                'code_verifier' => (string)$flow['code_verifier'],
                'state'         => $request->query->getString('state'),
                'device_id'     => $deviceId,
            ], '', '&', PHP_QUERY_RFC3986),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['code' => $code], '', '&', PHP_QUERY_RFC3986),
        );
        $accessToken = \is_string($token['access_token'] ?? null) ? $token['access_token'] : '';
        if ($accessToken === '') {
            throw new \RuntimeException('VK ID did not issue an access token.');
        }

        $profile = $this->requestJson(
            'POST',
            'https://id.vk.ru/oauth2/user_info?client_id=' . rawurlencode($this->settings->vkClientId()),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['access_token' => $accessToken], '', '&', PHP_QUERY_RFC3986),
        );
        $user = \is_array($profile['user'] ?? null) ? $profile['user'] : $profile;
        $subject = (string)($user['user_id'] ?? $user['id'] ?? $token['user_id'] ?? '');
        $email = (string)($user['email'] ?? $token['email'] ?? '');
        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        $avatar = (string)($user['avatar'] ?? $user['avatar_200'] ?? '');

        return $this->identity($provider, $subject, $email, $name, $avatar, $flow);
    }

    /** @param array<string, mixed> $flow */
    private function identity(
        string $provider,
        string $subject,
        string $email,
        string $name,
        string $avatar,
        array $flow,
    ): ExternalIdentity {
        if ($subject === '') {
            throw new \RuntimeException('The authentication provider returned no stable user identifier.');
        }

        if ($name === '') {
            $localPart = strstr($email, '@', true);
            $name = $email !== ''
                ? (\is_string($localPart) && $localPart !== '' ? $localPart : $email)
                : 'Участник';
        }

        return new ExternalIdentity(
            $provider,
            mb_substr($subject, 0, 191),
            mb_substr($email, 0, 80),
            mb_substr($name, 0, 80),
            mb_substr($avatar, 0, 1024),
            PublicReturnPath::normalize((string)($flow['return_path'] ?? '/')),
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $response = $this->httpClient->request($method, $url, $headers, $body, [
            'connect_timeout'   => 5,
            'read_timeout'      => 10,
            'follow_redirects'  => false,
            'max_response_bytes' => 262144,
        ]);
        if (!$response->isSuccessful() || !\is_string($response->content)) {
            throw new \RuntimeException('The authentication provider is temporarily unavailable.');
        }

        try {
            $decoded = json_decode($response->content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The authentication provider returned an invalid response.', 0, $exception);
        }

        if (!\is_array($decoded) || isset($decoded['error'])) {
            throw new \RuntimeException('The authentication provider rejected the authentication attempt.');
        }

        return $decoded;
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider !== 'yandex' && !\in_array($provider, self::VK_PROVIDERS, true)) {
            throw new \InvalidArgumentException('Unsupported public authentication provider.');
        }

        return $provider;
    }

    private function callbackUrl(string $provider): string
    {
        return html_entity_decode($this->urlBuilder->absLink('/auth/oauth/' . rawurlencode($provider) . '/callback'));
    }

    private function randomUrlToken(int $bytes): string
    {
        if ($bytes <= 0) {
            throw new \InvalidArgumentException('A random token must contain at least one byte.');
        }

        return $this->base64Url(random_bytes($bytes));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
