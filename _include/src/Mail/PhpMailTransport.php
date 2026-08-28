<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;

/** mail() fallback for hosts that disable subprocesses but expose the PHP mail facility. */
final class PhpMailTransport extends AbstractTransport
{
    /** @var \Closure(string, string, string, string, ?string): bool */
    private readonly \Closure $mailFunction;

    /** @param (callable(string, string, string, string, ?string): bool)|null $mailFunction */
    public function __construct(
        private readonly bool $useEnvelopeArgument,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface          $logger = null,
        ?callable                 $mailFunction = null,
    ) {
        parent::__construct($dispatcher, $logger);
        $this->mailFunction = $mailFunction !== null
            ? $mailFunction(...)
            : (static fn(string $to, string $subject, string $body, string $headers, ?string $parameters): bool => $parameters === null
                ? mail($to, $subject, $body, $headers)
                : mail($to, $subject, $body, $headers, $parameters));
    }

    #[\Override]
    public function __toString(): string
    {
        return 'php-mail://default';
    }

    #[\Override]
    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (!$original instanceof Message) {
            throw new TransportException('PHP mail requires a structured MIME message.');
        }

        [$headerBlock, $body] = $this->splitMessage($message->toString());
        [$subject, $headers] = $this->extractForMailFunction($headerBlock);
        $recipients = array_map(
            static fn(\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
            $message->getEnvelope()->getRecipients(),
        );
        $parameters = $this->useEnvelopeArgument
            ? '-f' . escapeshellarg($message->getEnvelope()->getSender()->getAddress())
            : null;

        $warning = null;
        set_error_handler(static function (int $_severity, string $text) use (&$warning): bool {
            $warning = $text;
            return true;
        });
        try {
            $accepted = ($this->mailFunction)(implode(', ', $recipients), $subject, $body, $headers, $parameters);
        } finally {
            restore_error_handler();
        }

        if (!$accepted) {
            $suffix = \is_string($warning) && $warning !== '' ? ': ' . $warning : '';
            throw new TransportException('PHP mail did not accept the message' . $suffix . '.');
        }
    }

    /** @return array{string, string} */
    private function splitMessage(string $raw): array
    {
        $parts = explode("\r\n\r\n", $raw, 2);
        if (\count($parts) !== 2) {
            throw new TransportException('Unable to split the MIME message for PHP mail.');
        }

        return [$parts[0], $parts[1]];
    }

    /** @return array{string, string} */
    private function extractForMailFunction(string $headerBlock): array
    {
        $fields = preg_split('/\r\n(?=[^ \t])/', $headerBlock);
        if (!\is_array($fields)) {
            throw new TransportException('Unable to parse MIME headers for PHP mail.');
        }

        $subject = '';
        $additional = [];
        foreach ($fields as $field) {
            $separator = strpos($field, ':');
            if ($separator === false) {
                continue;
            }

            $name = strtolower(substr($field, 0, $separator));
            if ($name === 'subject') {
                $subject = trim((string)preg_replace('/\r\n[ \t]+/', ' ', substr($field, $separator + 1)));
                continue;
            }

            if (\in_array($name, ['to', 'bcc', 'return-path'], true)) {
                continue;
            }

            $additional[] = $field;
        }

        if ($subject === '') {
            throw new TransportException('A PHP mail message has no subject.');
        }

        return [$subject, implode("\r\n", $additional)];
    }
}
