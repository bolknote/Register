<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register;

use Codeception\Test\Unit;
use Register\Module\AudioPlayer\Module as AudioPlayerModule;
use Register\Module\Analytics\AdminModule as AnalyticsAdminModule;
use Register\Module\Analytics\Module as AnalyticsModule;
use Register\Module\BaseModuleRegistry;
use Register\Module\Blog\AdminModule as BlogAdminModule;
use Register\Module\Blog\Module as BlogModule;
use Register\Module\Math\AdminModule as MathAdminModule;
use Register\Module\Math\Module as MathModule;
use Register\Module\Search\AdminModule as SearchAdminModule;
use Register\Module\Search\Module as SearchModule;
use Register\Module\SyntaxHighlighting\Module as SyntaxHighlightingModule;
use Register\Module\Typography\Module as TypographyModule;
use Register\ProductModule;
use Register\RegisterKernel;
use Register\Url\SlugGenerator;
use Register\Url\UniqueSlugGenerator;
use S2\Cms\Admin\AdminExtension;
use S2\Cms\CmsExtension;
use S2\Cms\Framework\Application;
use S2\Cms\Framework\ModuleInterface;

final class RegisterKernelTest extends Unit
{
    public function testRegistersPublicBaseModulesWithoutDatabaseState(): void
    {
        $application = new RecordingApplication();

        (new RegisterKernel(new BaseModuleRegistry()))->registerBaseModules($application, false);

        self::assertSame([
            CmsExtension::class,
            ProductModule::class,
            BlogModule::class,
            SearchModule::class,
            MathModule::class,
            AnalyticsModule::class,
            TypographyModule::class,
            SyntaxHighlightingModule::class,
            AudioPlayerModule::class,
        ], $application->moduleClasses);
    }

    public function testAddsAdminPartsForEveryBaseModuleThatHasOne(): void
    {
        $application = new RecordingApplication();

        (new RegisterKernel(new BaseModuleRegistry()))->registerBaseModules($application, true);

        self::assertSame([
            CmsExtension::class,
            ProductModule::class,
            AdminExtension::class,
            BlogModule::class,
            SearchModule::class,
            MathModule::class,
            AnalyticsModule::class,
            TypographyModule::class,
            SyntaxHighlightingModule::class,
            AudioPlayerModule::class,
            BlogAdminModule::class,
            SearchAdminModule::class,
            MathAdminModule::class,
            AnalyticsAdminModule::class,
        ], $application->moduleClasses);
    }

    public function testProductModuleRegistersCanonicalSlugServices(): void
    {
        $container = new \S2\Cms\Framework\Container([]);
        (new ProductModule(new BaseModuleRegistry()))->buildContainer($container);

        self::assertSame('new-post', $container->get(SlugGenerator::class)->generate('New post'));
        self::assertSame(
            'new-post',
            $container->get(UniqueSlugGenerator::class)->generate('New post', static fn(string $_slug): bool => true),
        );
    }
}

/** @internal */
final class RecordingApplication extends Application
{
    /** @var list<class-string<ModuleInterface>> */
    public array $moduleClasses = [];

    #[\Override]
    public function addModule(ModuleInterface $module): static
    {
        $this->moduleClasses[] = $module::class;

        return $this;
    }
}
