<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Mail;

use Codeception\Test\Unit;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Mail\MailDelivery;
use Register\Core\Mail\MailDeliveryInspector;
use Register\Core\Mail\MailDeliveryLog;
use Register\Core\Mail\DnsTxtLookupInterface;
use Register\Core\Mail\MailDnsInspector;
use Register\Core\Mail\MailMessage;
use Register\Core\Mail\MailSettings;
use Register\Core\Mail\PhpMailTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class MailSystemTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-mail-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create the mail test directory.');
        }
    }

    #[\Override]
    protected function _after(): void
    {
        $files = glob($this->directory . '/*');
        foreach ($files !== false ? $files : [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAutomaticTransportAndSmtpValidationAreProviderNeutral(): void
    {
        $phpSettings = $this->settings();
        self::assertSame(MailSettings::TRANSPORT_PHP_MAIL, $phpSettings->resolvedTransport());
        self::assertTrue($phpSettings->ready());

        $smtpSettings = $this->settings([
            MailSettings::SMTP_HOST_CONFIG_KEY => 'smtp.example.test',
            MailSettings::SMTP_USERNAME_CONFIG_KEY => 'sender',
            MailSettings::SMTP_PASSWORD_CONFIG_KEY => 'password',
        ]);
        self::assertSame(MailSettings::TRANSPORT_SMTP, $smtpSettings->resolvedTransport());
        self::assertTrue($smtpSettings->ready());

        $invalidSettings = $this->settings([
            MailSettings::TRANSPORT_CONFIG_KEY => MailSettings::TRANSPORT_SMTP,
            MailSettings::SMTP_HOST_CONFIG_KEY => '',
            MailSettings::SMTP_USERNAME_CONFIG_KEY => 'sender',
            MailSettings::SMTP_PASSWORD_CONFIG_KEY => '',
            MailSettings::SMTP_PORT_CONFIG_KEY => '70000',
        ]);
        self::assertEqualsCanonicalizing(
            ['smtp_host', 'smtp_port', 'smtp_credentials'],
            $invalidSettings->validationErrors(),
        );
    }

    public function testBase64EncodedDkimKeyIsHydratedWithoutEnteringPublicConfigLogic(): void
    {
        $privateKey = "-----BEGIN PRIVATE KEY-----\nprivate-test-value\n-----END PRIVATE KEY-----";
        $settings = $this->settings([
            MailSettings::DKIM_SELECTOR_CONFIG_KEY => 'mail',
            MailSettings::DKIM_DOMAIN_CONFIG_KEY => 'example.test',
            MailSettings::DKIM_PRIVATE_KEY_CONFIG_KEY => base64_encode($privateKey),
        ]);

        self::assertTrue($settings->dkimEnabled());
        self::assertSame($privateKey, $settings->dkimPrivateKey());
    }

    public function testDnsInspectionUsesOneBoundedBatchAndPreservesUnknownResults(): void
    {
        $lookup = new class implements DnsTxtLookupInterface {
            /** @var list<string> */
            public array $requestedNames = [];

            #[\Override]
            public function lookup(array $names): array
            {
                $this->requestedNames = $names;

                return [
                    'example.test' => ['v=spf1 -all'],
                    '_dmarc.example.test' => ['V=DMARC1; p=reject'],
                    'mail._domainkey.example.test' => null,
                ];
            }
        };
        $settings = $this->settings([
            MailSettings::DKIM_SELECTOR_CONFIG_KEY => 'mail',
            MailSettings::DKIM_DOMAIN_CONFIG_KEY => 'example.test',
        ]);

        self::assertSame([
            'available' => true,
            'domain' => 'example.test',
            'spf' => true,
            'dmarc' => true,
            'dkim' => null,
            'dkim_name' => 'mail._domainkey.example.test',
        ], (new MailDnsInspector($settings, $lookup))->inspect());
        self::assertSame([
            'example.test',
            '_dmarc.example.test',
            'mail._domainkey.example.test',
        ], $lookup->requestedNames);
    }

    public function testDnsInspectionFailsOpenWhenBoundedLookupIsUnavailable(): void
    {
        $lookup = new class implements DnsTxtLookupInterface {
            #[\Override]
            public function lookup(array $names): null
            {
                return null;
            }
        };

        self::assertSame([
            'available' => false,
            'domain' => 'example.test',
            'spf' => null,
            'dmarc' => null,
            'dkim' => null,
            'dkim_name' => null,
        ], (new MailDnsInspector($this->settings(), $lookup))->inspect());
    }

    public function testPhpMailTransportSeparatesEnvelopeHeadersAndMessageBody(): void
    {
        $call = null;
        $transport = new PhpMailTransport(
            true,
            mailFunction: static function (
                string  $to,
                string  $subject,
                string  $body,
                string  $headers,
                ?string $parameters,
            ) use (&$call): bool {
                $call = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers, 'parameters' => $parameters];
                return true;
            },
        );
        $email = (new Email())
            ->from(new Address('sender@example.test', 'Sender'))
            ->to('visible-recipient@example.test')
            ->bcc('hidden-recipient@example.test')
            ->subject('Transport test')
            ->text("private body\nsecond line")
        ;

        $transport->send($email, new Envelope(
            new Address('bounces@example.test'),
            [new Address('visible-recipient@example.test'), new Address('hidden-recipient@example.test')],
        ));

        self::assertIsArray($call);
        self::assertSame(
            'visible-recipient@example.test, hidden-recipient@example.test',
            $call['to'],
        );
        self::assertSame('Transport test', $call['subject']);
        self::assertStringContainsString('private body', $call['body']);
        self::assertStringContainsString('sender@example.test', $call['headers']);
        self::assertSame(0, preg_match('/(?:^|\r\n)(?:To|Bcc|Subject|Return-Path):/i', $call['headers']));
        self::assertSame("-f'bounces@example.test'", $call['parameters']);
    }

    public function testPhpMailTransportReportsRejection(): void
    {
        $transport = new PhpMailTransport(
            false,
            mailFunction: static fn(string $_to, string $_subject, string $_body, string $_headers, ?string $_params): bool => false,
        );
        $email = (new Email())
            ->from('sender@example.test')
            ->to('reader@example.test')
            ->subject('Rejected test')
            ->text('Body')
        ;

        $this->expectException(TransportExceptionInterface::class);
        $transport->send($email);
    }

    public function testDeliveryLogIsBoundedToOperationalMetadataAndPrivatePermissions(): void
    {
        $logFile = $this->directory . '/mail-delivery.jsonl';
        $log = new MailDeliveryLog($logFile, str_repeat('s', 32));
        $message = new MailMessage(
            type: 'privacy_test',
            recipientEmail: 'private-recipient@example.test',
            recipientName: 'Private Recipient',
            subject: 'Private subject marker',
            textBody: 'Private body marker',
        );
        $log->accepted($message, new MailDelivery('smtp', 'message-id', 12.34));
        $log->failed($message, 'smtp', 23.45, 451, 'Temporary transport failure.');

        $contents = file_get_contents($logFile);
        self::assertIsString($contents);
        self::assertStringContainsString('"type":"privacy_test"', $contents);
        self::assertStringContainsString('"recipient":"', $contents);
        self::assertStringNotContainsString('private-recipient@example.test', $contents);
        self::assertStringNotContainsString('Private Recipient', $contents);
        self::assertStringNotContainsString('Private subject marker', $contents);
        self::assertStringNotContainsString('Private body marker', $contents);
        clearstatcache(true, $logFile);
        $permissions = fileperms($logFile);
        self::assertIsInt($permissions);
        self::assertSame(0600, $permissions & 0777);

        $summary = (new MailDeliveryInspector($log))->inspect();
        self::assertSame(1, $summary['hour']['accepted']);
        self::assertSame(1, $summary['hour']['failed']);
        self::assertSame(12.3, $summary['hour']['p50_ms']);
        self::assertSame(23.5, $summary['hour']['p95_ms']);
        self::assertSame('failed', $summary['last']['status'] ?? null);
        self::assertSame(451, $summary['last']['error_code'] ?? null);
    }

    public function testMessageRejectsHeaderAndUnsubscribeInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MailMessage(
            type: 'security_test',
            recipientEmail: 'reader@example.test',
            recipientName: '',
            subject: 'Subject',
            textBody: 'Body',
            unsubscribeUrl: "https://example.test/unsubscribe\r\nBcc: attacker@example.test",
        );
    }

    /** @param array<string, string> $overrides */
    private function settings(array $overrides = []): MailSettings
    {
        $config = array_replace([
            MailSettings::TRANSPORT_CONFIG_KEY => MailSettings::TRANSPORT_AUTO,
            MailSettings::FROM_NAME_CONFIG_KEY => 'Register',
            MailSettings::FROM_EMAIL_CONFIG_KEY => 'sender@example.test',
            MailSettings::ENVELOPE_EMAIL_CONFIG_KEY => 'bounces@example.test',
            MailSettings::REPLY_TO_CONFIG_KEY => '',
            MailSettings::SMTP_HOST_CONFIG_KEY => '',
            MailSettings::SMTP_PORT_CONFIG_KEY => '587',
            MailSettings::SMTP_ENCRYPTION_CONFIG_KEY => MailSettings::ENCRYPTION_STARTTLS,
            MailSettings::SMTP_USERNAME_CONFIG_KEY => '',
            MailSettings::SMTP_PASSWORD_CONFIG_KEY => '',
            MailSettings::TIMEOUT_CONFIG_KEY => '8',
            MailSettings::PHP_ENVELOPE_CONFIG_KEY => '1',
            MailSettings::DKIM_SELECTOR_CONFIG_KEY => '',
            MailSettings::DKIM_DOMAIN_CONFIG_KEY => '',
            MailSettings::DKIM_PRIVATE_KEY_CONFIG_KEY => '',
        ], $overrides);
        $file = $this->directory . '/config-' . bin2hex(random_bytes(4)) . '.php';
        $written = file_put_contents($file, "<?php\n\nreturn " . var_export($config, true) . ';');
        if ($written === false) {
            throw new \RuntimeException('Unable to write the mail test configuration.');
        }

        return new MailSettings(new DynamicConfigProvider(fileName: $file));
    }
}
