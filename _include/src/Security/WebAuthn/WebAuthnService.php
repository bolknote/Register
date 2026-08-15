<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Security\WebAuthn;

use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final readonly class WebAuthnService
{
    public const string PURPOSE_AUTHENTICATE = 'authenticate';

    public const string PURPOSE_REGISTER = 'register';

    public function __construct(
        private DbLayer                          $dbLayer,
        private WebAuthnCredentialRepository     $credentialRepository,
        private WebAuthnChallengeRepository      $challengeRepository,
        private SerializerInterface              $serializer,
        private AttestationStatementSupportManager $attestationManager,
        private string                           $baseUrl,
        private bool                             $forceAdminHttps,
    ) {
    }

    /** @return array{options:array<string,mixed>,ceremony:WebAuthnChallenge} */
    public function beginAuthentication(Request $request, bool $remember): array
    {
        $this->assertRequestContext($request);
        $ceremony = $this->challengeRepository->issue(
            self::PURPOSE_AUTHENTICATE,
            $this->browserBinding($request),
            null,
            null,
            ['remember' => $remember],
        );
        $options = PublicKeyCredentialRequestOptions::create(
            $ceremony->challenge,
            $this->rpId(),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: WebAuthnChallengeRepository::LIFETIME_SECONDS * 1000,
            hints: ['client-device', 'hybrid', 'security-key'],
        );

        return ['options' => $this->normalize($options), 'ceremony' => $ceremony];
    }

    /** @return array{user_id:int,remember:bool} */
    public function finishAuthentication(Request $request, string $ceremonyToken, string $credentialJson): array
    {
        $this->assertRequestContext($request);
        $ceremony = $this->challengeRepository->consume(
            $ceremonyToken,
            self::PURPOSE_AUTHENTICATE,
            $this->browserBinding($request),
        );
        if (!$ceremony instanceof WebAuthnChallenge) {
            throw new \RuntimeException('The passkey request has expired or was already used.');
        }

        $publicKeyCredential = $this->deserializeCredential($credentialJson);
        if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw new \RuntimeException('An assertion response is required.');
        }

        $credential = $this->credentialRepository->find($publicKeyCredential->rawId);
        if (!$credential instanceof WebAuthnCredential || $publicKeyCredential->response->userHandle === null) {
            throw new \RuntimeException('The passkey cannot be used.');
        }

        $userId = $this->credentialRepository->userIdByHandle($publicKeyCredential->response->userHandle);
        if ($userId !== $credential->userId) {
            throw new \RuntimeException('The passkey user handle does not match.');
        }

        $options = PublicKeyCredentialRequestOptions::create(
            $ceremony->challenge,
            $this->rpId(),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: WebAuthnChallengeRepository::LIFETIME_SECONDS * 1000,
            hints: ['client-device', 'hybrid', 'security-key'],
        );
        $updatedRecord = AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory()->requestCeremony())
            ->check(
                $credential->record,
                $publicKeyCredential->response,
                $options,
                $this->rpId(),
                $publicKeyCredential->response->userHandle,
            )
        ;
        $this->credentialRepository->updateAfterUse($credential, $updatedRecord);

        return [
            'user_id'  => $credential->userId,
            'remember' => ($ceremony->context['remember'] ?? false) === true,
        ];
    }

    /** @return array{options:array<string,mixed>,ceremony:WebAuthnChallenge} */
    public function beginRegistration(
        Request $request,
        int $userId,
        string $sessionHash,
        string $credentialName,
    ): array {
        $this->assertRequestContext($request);
        $user = $this->user($userId);
        $name = trim($credentialName);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Passkey name must contain 1 to 100 characters.');
        }

        $ceremony = $this->challengeRepository->issue(
            self::PURPOSE_REGISTER,
            $this->browserBinding($request),
            $userId,
            $sessionHash,
            ['name' => $name],
        );
        $options = $this->registrationOptions($ceremony->challenge, $userId, $user);

        return ['options' => $this->normalize($options), 'ceremony' => $ceremony];
    }

    public function finishRegistration(
        Request $request,
        string $ceremonyToken,
        string $sessionHash,
        string $credentialJson,
    ): WebAuthnCredential {
        $this->assertRequestContext($request);
        $ceremony = $this->challengeRepository->consume(
            $ceremonyToken,
            self::PURPOSE_REGISTER,
            $this->browserBinding($request),
        );
        if (!$ceremony instanceof WebAuthnChallenge) {
            throw new \RuntimeException('The passkey registration has expired or belongs to another session.');
        }

        if (
            $ceremony->userId === null
            || $ceremony->sessionHash === null
            || !hash_equals($ceremony->sessionHash, $sessionHash)
        ) {
            throw new \RuntimeException('The passkey registration has expired or belongs to another session.');
        }

        $publicKeyCredential = $this->deserializeCredential($credentialJson);
        if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('An attestation response is required.');
        }

        $user = $this->user($ceremony->userId);
        $options = $this->registrationOptions($ceremony->challenge, $ceremony->userId, $user);
        $record = AuthenticatorAttestationResponseValidator::create($this->ceremonyFactory()->creationCeremony())
            ->check($publicKeyCredential->response, $options, $this->rpId())
        ;
        $name = $ceremony->context['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new \UnexpectedValueException('The passkey name is missing.');
        }

        $this->credentialRepository->add($ceremony->userId, $name, $record);

        return $this->credentialRepository->find($record->publicKeyCredentialId)
            ?? throw new \LogicException('The registered passkey was not stored.');
    }

    public function isAvailable(): bool
    {
        try {
            $origin = $this->origin();
        } catch (\RuntimeException) {
            return false;
        }

        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $host = parse_url($origin, PHP_URL_HOST);

        return $scheme === 'https' || ($scheme === 'http' && $host === 'localhost');
    }

    /** @param array{login:string,name:string} $user */
    private function registrationOptions(string $challenge, int $userId, array $user): PublicKeyCredentialCreationOptions
    {
        $descriptors = array_map(
            static fn(WebAuthnCredential $credential): \Webauthn\PublicKeyCredentialDescriptor => $credential->record->getPublicKeyCredentialDescriptor(),
            $this->credentialRepository->forUser($userId),
        );

        return PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('Register', $this->rpId()),
            PublicKeyCredentialUserEntity::create(
                $user['login'],
                $this->credentialRepository->userHandle($userId),
                $user['name'] !== '' ? $user['name'] : $user['login'],
            ),
            $challenge,
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $descriptors,
            WebAuthnChallengeRepository::LIFETIME_SECONDS * 1000,
            hints: ['client-device', 'hybrid', 'security-key'],
        );
    }

    private function deserializeCredential(string $json): PublicKeyCredential
    {
        if ($json === '' || strlen($json) > 131_072) {
            throw new \InvalidArgumentException('Invalid passkey response size.');
        }

        return $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
    }

    /** @return array<string, mixed> */
    private function normalize(object $options): array
    {
        $normalized = json_decode(
            $this->serializer->serialize($options, 'json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!\is_array($normalized)) {
            throw new \UnexpectedValueException('Unable to serialize WebAuthn options.');
        }

        return $normalized;
    }

    private function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin()]);
        $factory->setAttestationStatementSupportManager($this->attestationManager);

        return $factory;
    }

    /** @return array{login:string,name:string} */
    private function user(int $userId): array
    {
        $result = $this->dbLayer
            ->select('login, name')
            ->from('users')
            ->where('id = :id')->setParameter('id', $userId)
            ->execute()
        ;
        $row = $result->fetchAssoc();
        $result->freeResult();
        if ($row === false) {
            throw new \RuntimeException('The WebAuthn user no longer exists.');
        }

        return ['login' => (string)$row['login'], 'name' => (string)$row['name']];
    }

    private function assertRequestContext(Request $request): void
    {
        if (!$this->isAvailable() || !hash_equals($this->origin(), $request->getSchemeAndHttpHost())) {
            throw new \RuntimeException('Passkeys require the configured HTTPS origin.');
        }

        $source = $request->headers->get('Origin');
        if ($source === null || $source === '') {
            $source = $request->headers->get('Referer');
        }

        if ($source === null || $source === '') {
            throw new \RuntimeException('The WebAuthn request origin is missing.');
        }

        $sourceParts = parse_url($source);
        if (!\is_array($sourceParts) || !isset($sourceParts['scheme'], $sourceParts['host'])) {
            throw new \RuntimeException('Invalid WebAuthn request origin.');
        }

        $sourceOrigin = strtolower($sourceParts['scheme']) . '://' . strtolower($sourceParts['host'])
            . (isset($sourceParts['port']) ? ':' . $sourceParts['port'] : '');
        if (!hash_equals($this->origin(), $sourceOrigin)) {
            throw new \RuntimeException('The WebAuthn request came from another origin.');
        }
    }

    private function browserBinding(Request $request): string
    {
        return ($request->getClientIp() ?? '') . "\0" . (string)$request->headers->get('User-Agent');
    }

    private function origin(): string
    {
        $parts = parse_url($this->baseUrl);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('The configured base URL has no valid origin.');
        }

        $scheme = $this->forceAdminHttps ? 'https' : strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) && (($scheme !== 'https' || $parts['port'] !== 443) && ($scheme !== 'http' || $parts['port'] !== 80))
            ? ':' . $parts['port']
            : '';

        return $scheme . '://' . $host . $port;
    }

    private function rpId(): string
    {
        $host = parse_url($this->origin(), PHP_URL_HOST);

        return \is_string($host) && $host !== ''
            ? $host
            : throw new \RuntimeException('The configured base URL has no RP ID.');
    }
}
