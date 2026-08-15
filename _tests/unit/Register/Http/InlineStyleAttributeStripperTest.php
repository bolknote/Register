<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http;

use Codeception\Test\Unit;
use Register\Http\InlineStyleAttributeStripper;

final class InlineStyleAttributeStripperTest extends Unit
{
    public function testRemovesOnlyStyleAttributesWithoutNormalizingMarkup(): void
    {
        $html = <<<'HTML'
<!doctype html>
<div class="card" STYLE = "color: red" data-style="keep" title="literal style='keep'">
    <span style=color:blue data-value="a > b">Text</span>
    <i style data-kind="empty">Italic</i>
    <!-- <b style="keep">Comment</b> -->
    <script>const sample = '<b style="keep">Script text</b>';</script>
</div>
HTML;

        self::assertSame(<<<'HTML'
<!doctype html>
<div class="card" data-style="keep" title="literal style='keep'">
    <span data-value="a > b">Text</span>
    <i data-kind="empty">Italic</i>
    <!-- <b style="keep">Comment</b> -->
    <script>const sample = '<b style="keep">Script text</b>';</script>
</div>
HTML, (new InlineStyleAttributeStripper())->strip($html));
    }

    public function testReturnsUnchangedMarkupWhenThereIsNoStyleAttribute(): void
    {
        $html = '<p data-style="note">A style guide uses 1 < 2.</p>';

        self::assertSame($html, (new InlineStyleAttributeStripper())->strip($html));
    }
}
