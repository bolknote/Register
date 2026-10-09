<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Import;

use Codeception\Test\Unit;
use Register\Core\Comment\CommentHtml;
use Register\Core\HttpClient\HttpClientInterface;
use Register\Core\HttpClient\HttpResponse;
use Register\Import\Telegram\TelegramBotFileClient;
use Register\Import\Telegram\TelegramMediaDownloadFailed;
use Register\Import\Telegram\TelegramStickerAnimation;

final class TelegramBotFileClientTest extends Unit
{
    public function testDownloadsOnlyFromTelegramAndDoesNotExposeFileUrls(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnOnConsecutiveCalls(
            new HttpResponse(statusCode: 200, content: '{"ok":true,"result":{"file_path":"photos/file_1.jpg","file_size":3}}'),
            new HttpResponse(statusCode: 200, content: 'abc'),
        );
        self::assertSame('abc', (new TelegramBotFileClient($http, '100:fake-token'))->download(['file_id' => 'opaque-file-id']));
    }

    public function testRejectsExternalFilePathsAndRedactsFailures(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn(
            new HttpResponse(statusCode: 200, content: '{"ok":true,"result":{"file_path":"https://example.test/private"}}'),
        );
        try {
            (new TelegramBotFileClient($http, '100:fake-token'))->download(['file_id' => 'opaque-file-id']);
            self::fail('An arbitrary remote file path must not be requested.');
        } catch (TelegramMediaDownloadFailed $exception) {
            self::assertStringNotContainsString('fake-token', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testOversizedFilesDoNotStartADownload(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        self::assertNull((new TelegramBotFileClient($http, '100:fake-token'))->download(
            ['file_id' => 'opaque-file-id', 'file_size' => TelegramBotFileClient::MAX_BYTES + 1],
        ));
    }

    public function testDecodesRealTgsAndPreservesOnlyManagedAnimationMarkup(): void
    {
        $json = file_get_contents(__DIR__ . '/../../../_resources/telegram-sticker.json');
        self::assertIsString($json);
        $compressed = gzencode($json);
        self::assertIsString($compressed);
        self::assertSame(json_decode($json, true), json_decode(TelegramStickerAnimation::decode($compressed), true));
        $html = '<span class="comment-sticker" data-animation="/_pictures/bolknote/comments/telegram/123/2/01-0123456789abcdef0123.json" onclick="alert(1)">🙂</span>';
        $stored = CommentHtml::sanitizeImportedForStorage($html);
        self::assertStringContainsString('data-animation=', CommentHtml::render($stored, 'wrote:'));
        self::assertStringNotContainsString('onclick', $stored);
        self::assertStringNotContainsString('data-animation=', CommentHtml::sanitizeForStorage($html));
        self::assertStringNotContainsString('data-animation=', CommentHtml::sanitizeImportedForStorage(
            '<span class="comment-sticker" data-animation="https://elsewhere.test/a.json">🙂</span>',
        ));
    }

    public function testAnimatedStickersCannotLoadExternalAssets(): void
    {
        $json = file_get_contents(__DIR__ . '/../../../_resources/telegram-sticker.json');
        self::assertIsString($json);
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $data['assets'] = [['id' => 'remote', 'p' => 'https://elsewhere.test/tracker.png']];
        $compressed = gzencode(json_encode($data, JSON_THROW_ON_ERROR));
        self::assertIsString($compressed);
        self::expectException(\UnexpectedValueException::class);
        TelegramStickerAnimation::decode($compressed);
    }
}
