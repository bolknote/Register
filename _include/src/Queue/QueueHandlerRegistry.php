<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

final readonly class QueueHandlerRegistry
{
    /** @var array<non-empty-string, QueueHandlerInterface> */
    private array $handlers;

    /** @var array<non-empty-string, float> Values are validated as positive and finite at construction. */
    private array $minimumExecutionTimes;

    public function __construct(QueueHandlerInterface ...$handlers)
    {
        $handlersByCode          = [];
        $minimumExecutionByCode = [];
        foreach ($handlers as $handler) {
            $codes = $handler->codes();
            if ($codes === []) {
                throw new \InvalidArgumentException(\sprintf('Queue handler "%s" does not declare any codes.', $handler::class));
            }

            $minimumExecutionTime = $handler->minimumExecutionTime();
            if (!is_finite($minimumExecutionTime) || $minimumExecutionTime <= 0.0) {
                throw new \InvalidArgumentException(\sprintf(
                    'Queue handler "%s" must declare a positive finite minimum execution time.',
                    $handler::class,
                ));
            }

            foreach ($codes as $code) {
                if ($code === '') {
                    throw new \InvalidArgumentException('A queue handler code must not be empty.');
                }

                if (isset($handlersByCode[$code])) {
                    throw new \LogicException(\sprintf('More than one queue handler is registered for code "%s".', $code));
                }

                $handlersByCode[$code]          = $handler;
                $minimumExecutionByCode[$code] = $minimumExecutionTime;
            }
        }

        $this->handlers              = $handlersByCode;
        $this->minimumExecutionTimes = $minimumExecutionByCode;
    }

    public function get(string $code): QueueHandlerInterface
    {
        return $this->find($code)
            ?? throw new \UnexpectedValueException(\sprintf('No queue handler is registered for code "%s".', $code));
    }

    public function find(string $code): ?QueueHandlerInterface
    {
        return $this->handlers[$code] ?? null;
    }

    /**
     * @return list<non-empty-string>
     */
    public function codesExceedingBudget(QueueExecutionBudget $budget): array
    {
        $codes = [];
        foreach ($this->minimumExecutionTimes as $code => $minimumExecutionTime) {
            if (!$budget->canStart($minimumExecutionTime)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
