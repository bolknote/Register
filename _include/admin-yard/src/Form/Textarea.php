<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   AdminYard
 */

declare(strict_types=1);

namespace Register\AdminYard\Form;

class Textarea extends Input
{
    public function getHtml(?string $id = null): string
    {
        return sprintf(
            '<textarea name="%s"%s>%s</textarea>',
            htmlspecialchars($this->fieldName, ENT_QUOTES, 'UTF-8'),
            $id !== null ? ' id="' . $id . '"' : '',
            htmlspecialchars($this->value, ENT_QUOTES, 'UTF-8')
        );
    }
}
