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
use Register\Import\Telegram\TelegramStickerAnimation;

final class TelegramStickerTest extends Unit
{
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
