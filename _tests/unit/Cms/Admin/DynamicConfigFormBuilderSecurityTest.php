<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use S2\Cms\Admin\DynamicConfigFormBuilder;

final class DynamicConfigFormBuilderSecurityTest extends Unit
{
    public function testAkismetApiKeyIsTreatedAsASecret(): void
    {
        $reflection = new \ReflectionClass(DynamicConfigFormBuilder::class);
        $types = $reflection->getConstant('PARAM_TYPES');
        self::assertIsArray($types);

        $builder = $reflection->newInstanceWithoutConstructor();
        $builder->paramTypes = $types;

        self::assertTrue($builder->isSecretParameter('S2_AKISMET_KEY'));
    }
}
