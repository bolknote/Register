<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   AdminYard
 */

declare(strict_types=1);

namespace Register\AdminYard;

use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Database\TypeTransformer;
use Register\AdminYard\Form\FormControlFactory;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\Transformer\ViewTransformer;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Example of AdminYard factory. Feel free to create your own or use DI instead.
 */
class DefaultAdminFactory
{
    public static function createAdminPanel(
        AdminConfig $adminConfig,
        \PDO        $pdo,
        array       $translations = [],
        string      $locale = 'en'
    ): AdminPanel {
        $translator       = new Translator($translations, $locale);
        $templateRenderer = new TemplateRenderer($translator);
        $dataProvider     = new PdoDataProvider($pdo, new TypeTransformer());

        $eventDispatcher = new EventDispatcher();
        foreach ($adminConfig->getEntities() as $entityConfig) {
            foreach ($entityConfig->getListeners() as $eventName => $listeners) {
                foreach ($listeners as $listener) {
                    $eventDispatcher->addListener('adminyard.' . $eventName, $listener);
                }
            }
        }

        return new AdminPanel(
            $adminConfig,
            $eventDispatcher,
            $dataProvider,
            new ViewTransformer(),
            new MenuGenerator($adminConfig, $templateRenderer),
            $translator,
            $templateRenderer,
            new FormFactory(new FormControlFactory(), $translator, $dataProvider)
        );
    }
}
