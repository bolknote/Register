<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

use Register\Core\Config\DynamicConfigProvider;

/** Provider-neutral mail settings suitable for single-root shared hosting. */
final readonly class MailSettings
{
    public const string TRANSPORT_CONFIG_KEY = 'REGISTER_MAIL_TRANSPORT';

    public const string FROM_NAME_CONFIG_KEY = 'REGISTER_MAIL_FROM_NAME';

    public const string FROM_EMAIL_CONFIG_KEY = 'REGISTER_MAIL_FROM_EMAIL';

    public const string ENVELOPE_EMAIL_CONFIG_KEY = 'REGISTER_MAIL_ENVELOPE_EMAIL';

    public const string REPLY_TO_CONFIG_KEY = 'REGISTER_MAIL_REPLY_TO';

    public const string SMTP_HOST_CONFIG_KEY = 'REGISTER_MAIL_SMTP_HOST';

    public const string SMTP_PORT_CONFIG_KEY = 'REGISTER_MAIL_SMTP_PORT';

    public const string SMTP_ENCRYPTION_CONFIG_KEY = 'REGISTER_MAIL_SMTP_ENCRYPTION';

    public const string SMTP_USERNAME_CONFIG_KEY = 'REGISTER_MAIL_SMTP_USERNAME';

    public const string SMTP_PASSWORD_CONFIG_KEY = 'REGISTER_MAIL_SMTP_PASSWORD';

    public const string TIMEOUT_CONFIG_KEY = 'REGISTER_MAIL_TIMEOUT';

    public const string PHP_ENVELOPE_CONFIG_KEY = 'REGISTER_MAIL_PHP_ENVELOPE';

    public const string DKIM_SELECTOR_CONFIG_KEY = 'REGISTER_MAIL_DKIM_SELECTOR';

    public const string DKIM_DOMAIN_CONFIG_KEY = 'REGISTER_MAIL_DKIM_DOMAIN';

    public const string DKIM_PRIVATE_KEY_CONFIG_KEY = 'REGISTER_MAIL_DKIM_PRIVATE_KEY';

    public const string TRANSPORT_AUTO = 'auto';

    public const string TRANSPORT_SMTP = 'smtp';

    public const string TRANSPORT_PHP_MAIL = 'php_mail';

    public const string TRANSPORT_DISABLED = 'disabled';

    public const string ENCRYPTION_STARTTLS = 'starttls';

    public const string ENCRYPTION_TLS = 'tls';

    public const string ENCRYPTION_NONE = 'none';

    public function __construct(private DynamicConfigProvider $configProvider)
    {
    }

    public function configuredTransport(): string
    {
        return trim($this->string(self::TRANSPORT_CONFIG_KEY, self::TRANSPORT_AUTO));
    }

    public function resolvedTransport(): string
    {
        $configured = $this->configuredTransport();
        if ($configured !== self::TRANSPORT_AUTO) {
            return $configured;
        }

        return $this->smtpHost() !== ''
            ? self::TRANSPORT_SMTP
            : self::TRANSPORT_PHP_MAIL;
    }

    public function fromName(): string
    {
        return trim($this->string(self::FROM_NAME_CONFIG_KEY));
    }

    public function fromEmail(): string
    {
        return mb_strtolower(trim($this->string(self::FROM_EMAIL_CONFIG_KEY)));
    }

    public function envelopeEmail(): string
    {
        $configured = mb_strtolower(trim($this->string(self::ENVELOPE_EMAIL_CONFIG_KEY)));

        return $configured !== '' ? $configured : $this->fromEmail();
    }

    public function replyToEmail(): string
    {
        return mb_strtolower(trim($this->string(self::REPLY_TO_CONFIG_KEY)));
    }

    public function smtpHost(): string
    {
        return trim($this->string(self::SMTP_HOST_CONFIG_KEY));
    }

    public function smtpPort(): int
    {
        return $this->integer(self::SMTP_PORT_CONFIG_KEY, 587);
    }

    public function smtpEncryption(): string
    {
        return trim($this->string(self::SMTP_ENCRYPTION_CONFIG_KEY, self::ENCRYPTION_STARTTLS));
    }

    public function smtpUsername(): string
    {
        return trim($this->string(self::SMTP_USERNAME_CONFIG_KEY));
    }

    public function smtpPassword(): string
    {
        return $this->string(self::SMTP_PASSWORD_CONFIG_KEY);
    }

    public function timeout(): int
    {
        return $this->integer(self::TIMEOUT_CONFIG_KEY, 8);
    }

    public function usePhpEnvelopeArgument(): bool
    {
        return $this->boolean(self::PHP_ENVELOPE_CONFIG_KEY, true);
    }

    public function dkimSelector(): string
    {
        return trim($this->string(self::DKIM_SELECTOR_CONFIG_KEY));
    }

    public function dkimDomain(): string
    {
        $configured = mb_strtolower(trim($this->string(self::DKIM_DOMAIN_CONFIG_KEY)));
        if ($configured !== '') {
            return $configured;
        }

        $at = strrpos($this->fromEmail(), '@');

        return $at === false ? '' : substr($this->fromEmail(), $at + 1);
    }

    public function dkimPrivateKey(): string
    {
        $value = trim($this->string(self::DKIM_PRIVATE_KEY_CONFIG_KEY));
        if ($value === '') {
            return '';
        }

        if (!str_contains($value, '-----BEGIN')) {
            $decoded = base64_decode($value, true);
            if (\is_string($decoded) && str_contains($decoded, '-----BEGIN')) {
                $value = $decoded;
            }
        }

        return str_replace('\\n', "\n", $value);
    }

    public function dkimEnabled(): bool
    {
        return $this->dkimSelector() !== '' || $this->dkimPrivateKey() !== '';
    }

    /** @return list<string> Stable machine-readable reasons, suitable for translation by callers. */
    public function validationErrors(): array
    {
        $errors = [];
        $transport = $this->configuredTransport();
        if (!\in_array($transport, [
            self::TRANSPORT_AUTO,
            self::TRANSPORT_SMTP,
            self::TRANSPORT_PHP_MAIL,
            self::TRANSPORT_DISABLED,
        ], true)) {
            $errors[] = 'invalid_transport';
            return $errors;
        }

        if ($transport === self::TRANSPORT_DISABLED) {
            $errors[] = 'disabled';
            return $errors;
        }

        if (!$this->validEmail($this->fromEmail())) {
            $errors[] = 'from_email';
        }

        if (!$this->validEmail($this->envelopeEmail())) {
            $errors[] = 'envelope_email';
        }

        if ($this->replyToEmail() !== '' && !$this->validEmail($this->replyToEmail())) {
            $errors[] = 'reply_to_email';
        }

        if ($this->containsHeaderBreak($this->fromName())) {
            $errors[] = 'from_name';
        }

        $resolved = $this->resolvedTransport();
        if ($resolved === self::TRANSPORT_SMTP) {
            if ($this->smtpHost() === '' || $this->containsHeaderBreak($this->smtpHost())) {
                $errors[] = 'smtp_host';
            }

            if ($this->smtpPort() < 1 || $this->smtpPort() > 65535) {
                $errors[] = 'smtp_port';
            }

            if (!\in_array($this->smtpEncryption(), [
                self::ENCRYPTION_STARTTLS,
                self::ENCRYPTION_TLS,
                self::ENCRYPTION_NONE,
            ], true)) {
                $errors[] = 'smtp_encryption';
            }

            if (($this->smtpUsername() === '') !== ($this->smtpPassword() === '')) {
                $errors[] = 'smtp_credentials';
            }

            if ($this->smtpEncryption() !== self::ENCRYPTION_NONE && !\extension_loaded('openssl')) {
                $errors[] = 'openssl';
            }
        } elseif ($resolved === self::TRANSPORT_PHP_MAIL && !\function_exists('mail')) {
            $errors[] = 'php_mail';
        }

        if ($this->timeout() < 1 || $this->timeout() > 30) {
            $errors[] = 'timeout';
        }

        if ($this->dkimEnabled()) {
            if ($this->dkimSelector() === '' || preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/Di', $this->dkimSelector()) !== 1) {
                $errors[] = 'dkim_selector';
            }

            if ($this->dkimDomain() === '' || filter_var($this->dkimDomain(), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                $errors[] = 'dkim_domain';
            }

            if ($this->dkimPrivateKey() === '' || !\extension_loaded('openssl')) {
                $errors[] = 'dkim_key';
            }
        }

        return array_values(array_unique($errors));
    }

    public function ready(): bool
    {
        return $this->validationErrors() === [];
    }

    public function assertReady(): void
    {
        $errors = $this->validationErrors();
        if ($errors !== []) {
            throw new MailConfigurationException('Mail is not configured: ' . implode(', ', $errors) . '.');
        }
    }

    private function validEmail(string $email): bool
    {
        return $email !== ''
            && mb_strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && !$this->containsHeaderBreak($email);
    }

    private function containsHeaderBreak(string $value): bool
    {
        return str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0");
    }

    private function string(string $key, string $default = ''): string
    {
        try {
            $value = $this->configProvider->get($key);
        } catch (\LogicException) {
            return $default;
        }

        return \is_string($value) ? $value : $default;
    }

    private function integer(string $key, int $default): int
    {
        $value = $this->string($key, (string)$default);

        return preg_match('/^-?[0-9]+$/D', $value) === 1 ? (int)$value : $default;
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = $this->string($key, $default ? '1' : '0');

        return $value === '1';
    }
}
