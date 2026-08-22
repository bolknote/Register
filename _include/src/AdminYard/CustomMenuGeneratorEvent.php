<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\AdminYard;

class CustomMenuGeneratorEvent
{
    /**
     * @var array<string, Signal[]>
     */
    private array $signals = [];

    /**
     * @param array<mixed> $enabledEntities
     */
    public function __construct(public readonly array $enabledEntities)
    {
    }

    public function addSignal(string $entity, Signal $signal): void
    {
        $this->signals[$entity][] = $signal;
    }

    /**
     * @return array<string, Signal[]>
     */
    public function getSignals(): array
    {
        return $this->signals;
    }
}
