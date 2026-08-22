<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use Register\Core\Admin\ResourceProvider;

final class ResourceProviderTest extends Unit
{
    public function testBuiltInLanguageNamesAreLocalized(): void
    {
        $provider = new ResourceProvider(\dirname(__DIR__, 4) . '/');

        $englishOptions = $provider->readLanguageOptions('en');
        self::assertSame('English', $englishOptions['English']);
        self::assertSame('Russian', $englishOptions['Russian']);

        $russianOptions = $provider->readLanguageOptions('ru-RU');
        self::assertSame('Английский', $russianOptions['English']);
        self::assertSame('Русский', $russianOptions['Russian']);

        $fallbackOptions = $provider->readLanguageOptions('de');
        self::assertSame('English', $fallbackOptions['English']);
        self::assertSame('Russian', $fallbackOptions['Russian']);
    }

    public function testBuiltInStyleNamesAreLocalized(): void
    {
        $provider = new ResourceProvider(\dirname(__DIR__, 4) . '/');

        $englishOptions = $provider->readStyleOptions('en');
        self::assertSame('Register', $englishOptions['register']);
        self::assertSame('Pixel forest', $englishOptions['pixel-forest']);
        self::assertSame('System 1', $englishOptions['system-1']);

        $russianOptions = $provider->readStyleOptions('ru');
        self::assertSame('Регистр', $russianOptions['register']);
        self::assertSame('Пиксельный лес', $russianOptions['pixel-forest']);
        self::assertSame('Старая школа', $russianOptions['oldschool']);
        self::assertSame('System 1', $russianOptions['system-1']);
    }

    public function testStyleNameFallsBackToEnglishAndThenToHumanizedIdentifier(): void
    {
        $tempDir = \sys_get_temp_dir() . '/register_style_names_' . \bin2hex(\random_bytes(8));
        self::assertTrue(mkdir($tempDir . '/_styles/english-only', 0700, true));
        self::assertTrue(mkdir($tempDir . '/_styles/bare-theme', 0700, true));

        $styleDefinition = '<?php return null;';
        self::assertNotFalse(file_put_contents($tempDir . '/_styles/english-only/english-only.php', $styleDefinition));
        self::assertNotFalse(file_put_contents($tempDir . '/_styles/bare-theme/bare-theme.php', $styleDefinition));
        self::assertNotFalse(file_put_contents(
            $tempDir . '/_styles/english-only/style.json',
            json_encode(['name' => ['en' => 'English only']], JSON_THROW_ON_ERROR),
        ));

        try {
            $options = (new ResourceProvider($tempDir . '/'))->readStyleOptions('ru-RU');

            self::assertSame('English only', $options['english-only']);
            self::assertSame('Bare theme', $options['bare-theme']);
        } finally {
            @unlink($tempDir . '/_styles/english-only/style.json');
            @unlink($tempDir . '/_styles/english-only/english-only.php');
            @unlink($tempDir . '/_styles/bare-theme/bare-theme.php');
            @rmdir($tempDir . '/_styles/english-only');
            @rmdir($tempDir . '/_styles/bare-theme');
            @rmdir($tempDir . '/_styles');
            @rmdir($tempDir);
        }
    }
}
