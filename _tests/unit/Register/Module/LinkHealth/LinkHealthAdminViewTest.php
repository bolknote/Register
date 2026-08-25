<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\LinkHealthStatus;

final class LinkHealthAdminViewTest extends Unit
{
    public function testLongPaginationKeepsOnlyEdgesAndCurrentWindow(): void
    {
        $html = $this->renderView([
            'trans'      => static fn(string $key, array $_parameters = []): string => $key,
            'summary'    => [
                'total'           => 4_601,
                'usages'          => 4_601,
                'statuses'        => [LinkHealthStatus::BROKEN->value => 4_601],
                'inventory_ready' => true,
            ],
            'targets'    => [],
            'status'     => LinkHealthStatus::BROKEN,
            'page'       => 47,
            'pageCount'  => 93,
            'csrfToken'  => 'token',
            'autoRepair' => false,
            'canManage'  => true,
            'basePath'   => '',
        ]);

        self::assertStringContainsString('rel="prev"', $html);
        self::assertStringContainsString('rel="next"', $html);
        self::assertStringContainsString('aria-current="page">47</strong>', $html);
        self::assertStringContainsString('href="?entity=LinkHealth&amp;status=broken">1</a>', $html);
        foreach ([45, 46, 48, 49, 93] as $page) {
            self::assertStringContainsString('&amp;page=' . $page . '"', $html);
        }

        self::assertStringNotContainsString('&amp;page=44"', $html);
        self::assertStringNotContainsString('&amp;page=50"', $html);
        self::assertSame(2, substr_count($html, 'class="link-health-pagination-ellipsis"'));
    }

    /** @param array<string, mixed> $parameters */
    private function renderView(array $parameters): string
    {
        extract($parameters, EXTR_SKIP);

        ob_start();
        try {
            require \dirname(__DIR__, 5) . '/_include/src/Register/Module/LinkHealth/resources/views/admin.php.inc';
            $html = ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }

        if (!\is_string($html)) {
            throw new \LogicException('Unable to render the link-health admin view.');
        }

        return $html;
    }
}
