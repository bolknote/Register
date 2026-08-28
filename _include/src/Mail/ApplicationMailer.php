<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

final readonly class ApplicationMailer implements ApplicationMailerInterface
{
    public function __construct(
        private MailSettings         $settings,
        private MailTransportFactory $transportFactory,
        private MailDeliveryLog      $deliveryLog,
        private LoggerInterface      $logger,
    ) {
    }

    #[\Override]
    public function send(MailMessage $message): MailDelivery
    {
        $startedAt = microtime(true);
        $transportName = $this->transportFactory->name();
        try {
            $this->settings->assertReady();
            $recipient = new Address($message->recipientEmail, $message->recipientName);
            $from = new Address($this->settings->fromEmail(), $this->settings->fromName());
            $envelopeSender = new Address($this->settings->envelopeEmail());

            $email = (new Email())
                ->from($from)
                ->to($recipient)
                ->subject($message->subject)
                ->text($this->normalizeBody($message->textBody))
                ->date(new \DateTimeImmutable())
            ;
            if ($message->htmlBody !== null) {
                $email->html($this->normalizeBody($message->htmlBody));
            }

            $replyToEmail = $message->replyToEmail ?? $this->settings->replyToEmail();
            if ($replyToEmail !== '') {
                $email->replyTo(new Address($replyToEmail, $message->replyToName));
            }

            $headers = $email->getHeaders();
            $headers->addTextHeader('X-Mailer', 'Register Mailer');
            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
            $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
            if ($message->unsubscribeUrl !== null) {
                $headers->addTextHeader('List-Unsubscribe', '<' . $message->unsubscribeUrl . '>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }

            $outgoing = $this->sign($email);
            $previousSocketTimeout = '';
            if ($transportName === MailSettings::TRANSPORT_SMTP) {
                $previousSocketTimeout = ini_get('default_socket_timeout');
                ini_set('default_socket_timeout', (string)$this->settings->timeout());
            }

            try {
                $sent = $this->transportFactory->get()->send(
                    $outgoing,
                    new Envelope($envelopeSender, [$recipient]),
                );
            } finally {
                if ($transportName === MailSettings::TRANSPORT_SMTP) {
                    ini_set('default_socket_timeout', $previousSocketTimeout);
                }
            }

            if ($sent === null) {
                throw new \RuntimeException('The mail transport rejected the message without an error.');
            }

            $delivery = new MailDelivery(
                $transportName,
                $sent->getMessageId(),
                (microtime(true) - $startedAt) * 1000.0,
            );
            $this->recordAccepted($message, $delivery);

            return $delivery;
        } catch (MailDeliveryException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            $duration = (microtime(true) - $startedAt) * 1000.0;
            $code = $this->smtpCode($throwable);
            $safeError = $this->safeError($throwable);
            $permanent = $throwable instanceof MailConfigurationException
                || $throwable instanceof \InvalidArgumentException
                || ($code !== null && $code >= 500 && $code <= 599);
            $this->recordFailed($message, $transportName, $duration, $code, $safeError);
            $this->throwDeliveryException($safeError, $permanent, $code);
        }
    }

    /** Keep transport exceptions out of the public chain: they can contain SMTP credentials. */
    private function throwDeliveryException(string $error, bool $permanent, ?int $code): never
    {
        throw new MailDeliveryException($error, $permanent, $code);
    }

    private function sign(Email $email): Message
    {
        if (!$this->settings->dkimEnabled()) {
            return $email;
        }

        try {
            return (new DkimSigner(
                $this->settings->dkimPrivateKey(),
                $this->settings->dkimDomain(),
                $this->settings->dkimSelector(),
            ))->sign($email);
        } catch (\Throwable $throwable) {
            throw new MailConfigurationException('Unable to sign mail with the configured DKIM key.', 0, $throwable);
        }
    }

    private function normalizeBody(string $body): string
    {
        return str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $body);
    }

    private function smtpCode(\Throwable $throwable): ?int
    {
        $code = $throwable->getCode();
        if ($throwable instanceof TransportExceptionInterface && \is_int($code) && $code >= 400 && $code <= 599) {
            return $code;
        }

        if (preg_match('/(?:^|\D)([45][0-9]{2})(?:\D|$)/', $throwable->getMessage(), $matches) === 1) {
            return (int)$matches[1];
        }

        return null;
    }

    private function safeError(\Throwable $throwable): string
    {
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $throwable->getMessage());
        if (!\is_string($message) || trim($message) === '') {
            $message = 'Mail transport failed.';
        }

        foreach ([$this->settings->smtpPassword(), $this->settings->dkimPrivateKey()] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[secret]', $message);
            }
        }

        return mb_substr(trim($message), 0, 500);
    }

    private function recordAccepted(MailMessage $message, MailDelivery $delivery): void
    {
        try {
            $this->deliveryLog->accepted($message, $delivery);
        } catch (\Throwable $throwable) {
            $this->logger->warning('Unable to record accepted mail delivery.', ['exception' => $throwable]);
        }
    }

    private function recordFailed(
        MailMessage $message,
        string      $transport,
        float       $duration,
        ?int        $code,
        string      $error,
    ): void {
        try {
            $this->deliveryLog->failed($message, $transport, $duration, $code, $error);
        } catch (\Throwable $throwable) {
            $this->logger->warning('Unable to record failed mail delivery.', ['exception' => $throwable]);
        }
    }
}
