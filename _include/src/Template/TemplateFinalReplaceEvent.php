<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Template;

class TemplateFinalReplaceEvent
{
    private string $hash = '';

    public function __construct(public string &$template)
    {
    }

    public function replace(string $placeholder, string $value): void
    {
        $this->template = str_replace($placeholder, $value, $this->template);
        $this->hash     .= md5($value);
    }

    public function setTemplate(string $template): void
    {
        $this->template = $template;
        $this->hash    .= md5($template);
    }

    public function getHash(): string
    {
        return $this->hash;
    }
}
