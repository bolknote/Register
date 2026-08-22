<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Ai;

use PHPUnit\Framework\TestCase;
use Register\Ai\ImageAltPolicy;

final class ImageAltPolicyTest extends TestCase
{
    public function testPromptDistinguishesScenesFromTextHeavyImages(): void
    {
        $prompt = ImageAltPolicy::buildPrompt('Заголовок', '<p>Контекст</p>', 'Russian');

        self::assertStringContainsString('photograph or illustration with a scene', $prompt);
        self::assertStringContainsString('document, screenshot, interface, diagram, chart, or meme', $prompt);
        self::assertStringContainsString('main readable text or data', $prompt);
        self::assertStringContainsString('Write the answer in Russian.', $prompt);
        self::assertStringContainsString('Заголовок', $prompt);
        self::assertStringContainsString('<p>Контекст</p>', $prompt);
    }

    public function testNormalizationRemovesWrappersAndFitsACompleteResultToTheLimit(): void
    {
        $result = ImageAltPolicy::normalize(
            "```text\nAlt: «" . str_repeat('Подробность ', 30) . "завершение»\n```",
        );

        self::assertNotSame('', $result);
        self::assertLessThanOrEqual(ImageAltPolicy::MAX_LENGTH, mb_strlen($result));
        self::assertStringEndsWith('…', $result);
        self::assertStringNotContainsString('Alt:', $result);
    }

    public function testLegitimateJapaneseTextIsAccepted(): void
    {
        self::assertTrue(ImageAltPolicy::isAcceptable('На футболке написано «変態の流れ», рядом нарисован кот.'));
    }

    public function testModelReasoningAndGenericOpeningsAreRejected(): void
    {
        self::assertSame('', ImageAltPolicy::normalize('<think>Internal reasoning</think>A cat on a chair.'));
        self::assertFalse(ImageAltPolicy::isAcceptable('<think>Internal reasoning</think>A cat on a chair.'));
        self::assertFalse(ImageAltPolicy::isAcceptable('A photo of a cat on a chair.'));
        self::assertFalse(ImageAltPolicy::isAcceptable('Изображение кота на стуле.'));
    }
}
