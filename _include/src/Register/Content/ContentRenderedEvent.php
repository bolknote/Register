<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Core\Template\HtmlTemplate;

/** Fired once a complete public content item has been placed in its HTML template. */
final readonly class ContentRenderedEvent
{
    public function __construct(
        public HtmlTemplate $template,
        public ContentId    $contentId,
    ) {
    }
}
