<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Template;

use Codeception\Test\Unit;
use S2\Cms\Template\TemplateFinalReplaceEvent;

final class TemplateFinalReplaceEventTest extends Unit
{
    public function testReplacingTheCompleteTemplateContributesToTheEtagHash(): void
    {
        $html  = '<p style="color: red">Text</p>';
        $event = new TemplateFinalReplaceEvent($html);

        $event->setTemplate('<p>Text</p>');

        self::assertSame('<p>Text</p>', $event->template);
        self::assertSame(md5('<p>Text</p>'), $event->getHash());
    }
}
