<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model\Article;

use Register\Core\Template\HtmlTemplate;

readonly class ArticleRenderedEvent
{
    public function __construct(public HtmlTemplate $template, public int $articleId)
    {
    }
}
