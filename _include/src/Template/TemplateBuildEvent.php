<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Template;

class TemplateBuildEvent
{
    public const string EVENT_START = 'template_build.start';

    public const string EVENT_END = 'template_build.end';

    public function __construct(
        public readonly string $styleName,
        public readonly string $templateId,
        public ?string &$path
    ) {
    }
}
