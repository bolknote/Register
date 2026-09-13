<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Mail;

use Codeception\Test\Unit;
use Register\Core\Mail\ApplicationMailerInterface;
use Register\Core\Mail\CommentMailer;
use Register\Core\Mail\MailDelivery;
use Register\Core\Mail\MailMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CommentMailerTest extends Unit
{
    public function testCommentMessagesUseReadableSafeHtmlWithoutClassifierDetails(): void
    {
        $transport = new class implements ApplicationMailerInterface {
            /** @var list<MailMessage> */
            public array $messages = [];

            #[\Override]
            public function send(MailMessage $message): MailDelivery
            {
                $this->messages[] = $message;

                return new MailDelivery('test', 'comment-message@example.test', 0.0);
            }
        };
        $mailer = new CommentMailer($this->translator(), $transport);
        $url = 'https://example.test/post?from=mail&item=1#comment-42';

        $mailer->mailToSubscriber(
            'Читатель',
            'reader@example.test',
            "Первая строка\nВторая <строка>",
            'Церкви & храмы',
            $url,
            'Автор <комментария>',
            'https://example.test/unsubscribe?item=1&token=secret',
        );
        $mailer->mailToReplyRecipient(
            'Евгений Степанищев',
            'owner@example.test',
            "Первая строка\nВторая <строка>",
            'Церкви & храмы',
            $url,
            'Автор <комментария>',
        );
        $mailer->mailToModerator(
            'admin',
            'admin@example.test',
            "Первая строка\nВторая <строка>",
            'Церкви & храмы',
            $url,
            'Автор <комментария>',
            'author@example.test',
            true,
            'ham',
        );

        self::assertCount(3, $transport->messages);
        foreach ($transport->messages as $message) {
            self::assertNotNull($message->htmlBody);
            self::assertStringContainsString('<p>', (string) $message->htmlBody);
            self::assertStringContainsString('<hr>', (string) $message->htmlBody);
            self::assertStringContainsString('Первая строка<br', (string) $message->htmlBody);
            self::assertStringContainsString('Вторая &lt;строка&gt;', (string) $message->htmlBody);
            self::assertStringContainsString('Церкви &amp; храмы', (string) $message->htmlBody);
            self::assertStringNotContainsString('----------------------------------------------------------------------', (string) $message->htmlBody);
            self::assertStringNotContainsString('<pre', (string) $message->htmlBody);
            self::assertStringNotContainsString('font-family', (string) $message->htmlBody);
            self::assertStringNotContainsString('background', (string) $message->htmlBody);
            self::assertStringNotContainsString('color:', (string) $message->htmlBody);
        }

        $moderatorMessage = $transport->messages[2];
        self::assertStringContainsString('Комментарий опубликован автоматически.', $moderatorMessage->textBody);
        self::assertStringContainsString('Комментарий опубликован автоматически.', $moderatorMessage->htmlBody ?? '');
        self::assertStringNotContainsString('report=', $moderatorMessage->textBody);
        self::assertStringNotContainsString('report=', $moderatorMessage->htmlBody ?? '');
        self::assertStringNotContainsString('ham', $moderatorMessage->textBody);
        self::assertStringNotContainsString('ham', $moderatorMessage->htmlBody ?? '');
    }

    private function translator(): TranslatorInterface
    {
        /** @var array<string, string> $translations */
        $translations = require \dirname(__DIR__, 4) . '/_lang/Russian/comments.php';
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn(?string $id): string => $id !== null && isset($translations[$id]) ? $translations[$id] : (string)$id,
        );

        return $translator;
    }
}
