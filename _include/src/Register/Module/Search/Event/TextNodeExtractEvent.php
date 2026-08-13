<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Event;

use S2\Rose\Extractor\HtmlDom\DomState;
use Symfony\Contracts\EventDispatcher\Event;

class TextNodeExtractEvent extends Event
{
    public function __construct(
        public readonly \DOMNode $parentNode,
        public readonly DomState $domState,
        public readonly string   $textContent,
        public readonly string   $path
    ) {
    }
}
