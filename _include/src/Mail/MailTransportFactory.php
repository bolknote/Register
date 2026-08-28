<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/** Lazily creates and reuses one connection during a bounded queue batch. */
final class MailTransportFactory
{
    private ?TransportInterface $transport = null;

    public function __construct(
        private readonly MailSettings    $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(): TransportInterface
    {
        $this->settings->assertReady();

        return $this->transport ??= match ($this->settings->resolvedTransport()) {
            MailSettings::TRANSPORT_SMTP => $this->smtp(),
            MailSettings::TRANSPORT_PHP_MAIL => new PhpMailTransport(
                $this->settings->usePhpEnvelopeArgument(),
                logger: $this->logger,
            ),
            default => throw new MailConfigurationException('The selected mail transport is unavailable.'),
        };
    }

    public function name(): string
    {
        return $this->settings->resolvedTransport();
    }

    private function smtp(): EsmtpTransport
    {
        $encryption = $this->settings->smtpEncryption();
        $transport = new EsmtpTransport(
            $this->settings->smtpHost(),
            $this->settings->smtpPort(),
            $encryption === MailSettings::ENCRYPTION_TLS,
            logger: $this->logger,
        );
        $transport->setAutoTls($encryption === MailSettings::ENCRYPTION_STARTTLS);
        $transport->setRequireTls($encryption === MailSettings::ENCRYPTION_STARTTLS);
        $transport->setRestartThreshold(20);

        if ($this->settings->smtpUsername() !== '') {
            $transport->setUsername($this->settings->smtpUsername());
            $transport->setPassword($this->settings->smtpPassword());
        }

        return $transport;
    }
}
