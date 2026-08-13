<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

final readonly class QueueHandlerRegistry
{
    /** @var array<non-empty-string, QueueHandlerInterface> */
    private array $handlers;

    public function __construct(QueueHandlerInterface ...$handlers)
    {
        $handlersByCode = [];
        foreach ($handlers as $handler) {
            $codes = $handler->codes();
            if ($codes === []) {
                throw new \InvalidArgumentException(\sprintf('Queue handler "%s" does not declare any codes.', $handler::class));
            }

            foreach ($codes as $code) {
                if ($code === '') {
                    throw new \InvalidArgumentException('A queue handler code must not be empty.');
                }

                if (isset($handlersByCode[$code])) {
                    throw new \LogicException(\sprintf('More than one queue handler is registered for code "%s".', $code));
                }

                $handlersByCode[$code] = $handler;
            }
        }

        $this->handlers = $handlersByCode;
    }

    public function get(string $code): QueueHandlerInterface
    {
        return $this->handlers[$code]
            ?? throw new \UnexpectedValueException(\sprintf('No queue handler is registered for code "%s".', $code));
    }
}
