<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Event;

class AdminAjaxControllerMapEvent
{
    /**
     * @param array<string, callable> $controllerMap
     * @param list<string>            $readOnlyActions
     */
    public function __construct(
        public array $controllerMap,
        private array $readOnlyActions = [],
    ) {
    }

    public function allowGet(string $action): void
    {
        if (!\in_array($action, $this->readOnlyActions, true)) {
            $this->readOnlyActions[] = $action;
        }
    }

    public function allowsGet(string $action): bool
    {
        return \in_array($action, $this->readOnlyActions, true);
    }
}
