<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module;

use Codeception\Test\Unit;
use Register\Module\BaseModuleRegistry;

final class BaseModuleRegistryTest extends Unit
{
    public function testContainsEveryModuleThatFormsTheRegisterProduct(): void
    {
        self::assertSame([
            's2_blog',
            's2_search',
            's2_latex',
            'register_visitor_identity',
            's2_counter',
            'register_reactions',
            's2_typo',
            'register_syntax_highlighting',
            'register_audio_player',
            'register_link_health',
        ], $this->registry()->ids());
    }

    public function testBaseModuleClassesAreLoadable(): void
    {
        foreach ($this->registry()->ids() as $id) {
            self::assertTrue(class_exists($this->registry()->manifestClass($id)));
        }

        foreach ($this->registry()->applicationModuleClasses() as $class) {
            self::assertTrue(class_exists($class));
        }

        foreach ($this->registry()->adminModuleClasses() as $class) {
            self::assertTrue(class_exists($class));
        }
    }

    public function testDistinguishesBaseModulesFromFutureOptionalModules(): void
    {
        self::assertTrue($this->registry()->contains('s2_blog'));
        self::assertFalse($this->registry()->contains('example_optional_module'));
    }

    public function testRejectsUnknownManifestLookup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry()->manifestClass('example_optional_module');
    }

    private function registry(): BaseModuleRegistry
    {
        return new BaseModuleRegistry();
    }
}
