<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use S2\Cms\AdminYard\Form\CustomAutocomplete;

final class CustomAutocompleteTest extends Unit
{
    public function testRendersConfigurationAsDataAttributes(): void
    {
        $control = new CustomAutocomplete('author_id');
        $control->setAutocompleteParams(
            'User',
            'hash-value',
            static fn(string $value, int $limit = 20): array => array_slice(
                [['value' => $value, 'text' => '<Administrator>']],
                0,
                $limit,
            ),
            true,
        );
        $control->setValue('42');

        $html = $control->getHtml('author');

        self::assertStringContainsString('data-autocomplete-control', $html);
        self::assertStringContainsString('data-fetch-url="?entity=User&amp;hash=hash-value&amp;action=autocomplete"', $html);
        self::assertStringContainsString('&lt;Administrator&gt;', $html);
        self::assertStringNotContainsString('<script', $html);
    }
}
