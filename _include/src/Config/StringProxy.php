<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Config;
use Register\Core\Pdo\DbLayerException;


final readonly class StringProxy implements \Stringable
{
    public function __construct(
        private DynamicConfigProvider $provider,
        private string                $paramName,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function get(): string
    {
        $value = $this->provider->get($this->paramName);
        if (\is_string($value)) {
            return $value;
        }

        throw new \LogicException(\sprintf('Dynamic config param "%s" must be a string.', $this->paramName));
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->get();
    }
}
