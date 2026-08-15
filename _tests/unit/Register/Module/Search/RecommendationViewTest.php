<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search;

use Codeception\Test\Unit;
use Register\Module\Search\Layout\ImgDto;

final class RecommendationViewTest extends Unit
{
    public function testRendersImagesAndGridGeometryWithoutInlineStyles(): void
    {
        $html = $this->render([
            $this->item('1/1/3/3', new ImgDto('/wide.jpg', 800, 400, '')),
            $this->item('1/3', new ImgDto('/right.jpg', 300, 450, 'right')),
            $this->item('2/4', new ImgDto('/compact.jpg', 200, 320, 'right2')),
            $this->item('2/5/3/7', new ImgDto('/thumb.jpg', 120, 90, 'thumb')),
        ]);

        self::assertStringNotContainsString('<style', $html);
        self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $html);
        self::assertStringContainsString('recommendations recommendations-columns-6', $html);
        self::assertStringContainsString(
            'recommendation-grid-row-start-1 recommendation-grid-column-start-1 recommendation-grid-row-end-3 recommendation-grid-column-end-3',
            $html,
        );
        self::assertStringContainsString('recommendation-img-right-wide', $html);
        self::assertStringContainsString('recommendation-img-right-compact', $html);
        self::assertStringContainsString('recommendation-img-thumb-wrapper', $html);
        self::assertStringContainsString("width='800' height='400'", $html);
    }

    public function testInvalidGridPositionCannotBecomeMarkup(): void
    {
        $html = $this->render([
            $this->item('1/1" onmouseover="alert(1)', null),
        ]);

        self::assertStringNotContainsString('onmouseover', $html);
        self::assertStringNotContainsString('alert(1)', $html);
        self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $html);
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function render(array $content): string
    {
        $trans       = static fn(string $message): string => $message;
        $makeLink    = static fn(string $path): string => $path;
        $dateAndTime = static fn(int $timestamp): string => (string)$timestamp;
        $raw         = [];
        $log         = ['recommendation-view-test'];

        ob_start();
        include \dirname(__DIR__, 5) . '/_include/src/Register/Module/Search/resources/views/recommendations.php';
        $html = ob_get_clean();
        if (!\is_string($html)) {
            throw new \RuntimeException('Unable to render the recommendations fixture.');
        }

        return $html;
    }

    /** @return array<string, mixed> */
    private function item(string $position, ?ImgDto $image): array
    {
        return [
            'position'    => $position,
            'image'       => $image,
            'headingSize' => 1,
            'url'         => '/article',
            'title'       => 'Article title',
            'snippet'     => 'Article snippet',
            'date'        => null,
        ];
    }
}
