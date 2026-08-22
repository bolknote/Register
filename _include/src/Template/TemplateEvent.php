<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Template;

use Symfony\Contracts\EventDispatcher\Event;

class TemplateEvent extends Event
{
    public const string EVENT_CREATED = 'template.created';

    public const string EVENT_PRE_REPLACE = 'template.pre_replace';

    public function __construct(public readonly HtmlTemplate $htmlTemplate)
    {
    }
}
