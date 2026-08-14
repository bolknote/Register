<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Typography;

use Codeception\Test\Unit;
use Register\Module\Typography\Module;
use S2\Cms\Controller\Rss\FeedItemDto;
use S2\Cms\Controller\Rss\FeedItemRenderEvent;
use S2\Cms\Framework\Container;
use S2\Cms\Template\TemplateFinalReplaceEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LocaleTranslator implements TranslatorInterface
{
    public function __construct(private string $locale)
    {
    }

    /** @param array<mixed> $parameters */
    #[\Override]
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }

    #[\Override]
    public function getLocale(): string
    {
        return $this->locale;
    }
}

final class ModuleTest extends Unit
{
    /**
     * @dataProvider localeProvider
     */
    public function testRenderingUsesActiveLocale(
        string $locale,
        string $expectedHtml,
        string $expectedFeedTitle,
    ): void {
        $container = new Container([]);
        $container->set('translator', new LocaleTranslator($locale));

        $eventDispatcher = new EventDispatcher();
        (new Module())->registerListeners($eventDispatcher, $container);

        $html  = '<p>Он и она</p>';
        $event = new TemplateFinalReplaceEvent($html);
        $eventDispatcher->dispatch($event);
        $eventDispatcher->dispatch($event);

        self::assertSame($expectedHtml, $html);

        $feedItem = new FeedItemDto('Он и она', 'Author', '/', '<p>Он и она</p>', 0, 0);
        $eventDispatcher->dispatch(new FeedItemRenderEvent($feedItem));
        $eventDispatcher->dispatch(new FeedItemRenderEvent($feedItem));

        self::assertSame($expectedFeedTitle, $feedItem->title);
        self::assertSame($expectedHtml, $feedItem->text);
    }

    public static function localeProvider(): \Iterator
    {
        yield 'Russian' => ['ru', '<p>Он и&nbsp;она</p>', "Он и\u{00A0}она"];
        yield 'English' => ['en', '<p>Он и она</p>', 'Он и она'];
    }
}
