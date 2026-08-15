<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use S2\Cms\Admin\AdminExtension;
use S2\Cms\AdminYard\CustomTemplateRendererEvent;
use S2\Cms\AdminYard\Form\CustomAutocomplete;
use S2\Cms\Framework\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
        self::assertStringContainsString('aria-haspopup="listbox"', $html);
        self::assertStringContainsString('aria-expanded="false"', $html);
        self::assertStringContainsString('class="ay-select-dropdown" id="author-control-dropdown" hidden', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function testAdminExtensionPublishesCspSafeAutocompleteController(): void
    {
        $dispatcher = new EventDispatcher();
        (new AdminExtension())->registerListeners($dispatcher, new Container([]));

        $event = new CustomTemplateRendererEvent('/blog');
        $dispatcher->dispatch($event);

        self::assertContains('/blog/_admin/js/autocomplete.js', $event->extraScripts);

        $script = (string)file_get_contents('_admin/js/autocomplete.js');
        self::assertStringContainsString('dropdown.hidden', $script);
        self::assertStringNotContainsString('.style', $script);
        self::assertStringNotContainsString('.innerHTML', $script);
    }
}
