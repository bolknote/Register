<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Ai;

final readonly class AiImageInput
{
    public function __construct(
        public string $mimeType,
        public string $data,
    ) {
    }
}
