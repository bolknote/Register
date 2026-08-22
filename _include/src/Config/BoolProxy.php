<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Config;
use Register\Core\Pdo\DbLayerException;


final readonly class BoolProxy
{
    public function __construct(
        private DynamicConfigProvider $provider,
        private string                $paramName,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function get(): bool
    {
        $value = $this->provider->get($this->paramName);

        return $value === '1';
    }
}
