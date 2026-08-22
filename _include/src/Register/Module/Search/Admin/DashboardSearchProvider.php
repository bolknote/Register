<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Admin;

use Register\AdminYard\TemplateRenderer;
use Register\Core\Admin\Dashboard\SystemStatusProviderInterface;
use Register\Rose\Storage\Database\PdoStorage;

readonly class DashboardSearchProvider implements SystemStatusProviderInterface
{
    public function __construct(
        private TemplateRenderer  $templateRenderer,
        private PdoStorage        $pdoStorage,
        private ReindexToken      $reindexToken,
        private SearchIndexHealth $searchIndexHealth,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        try {
            $stat = $this->pdoStorage->getIndexStat();
        } catch (\Throwable) {
            $stat = ['rows' => 0, 'bytes' => 0];
        }

        return $this->templateRenderer->render(
            \dirname(__DIR__) . '/resources/views/dashboard/search-item.php.inc',
            [
                ...$stat,
                'csrfToken' => $this->reindexToken->value(),
                'health'    => $this->searchIndexHealth->inspect(),
            ],
        );
    }
}
