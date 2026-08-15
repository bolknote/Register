<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use S2\AdminYard\Config\FieldConfig;
use S2\Cms\Admin\DynamicConfigFormBuilder;
use S2\Cms\Model\PermissionChecker;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    public function testAdminColorAcceptsOnlySixDigitHexValues(): void
    {
        $reflection = new \ReflectionClass(DynamicConfigFormBuilder::class);
        $types = $reflection->getConstant('PARAM_TYPES');
        self::assertIsArray($types);

        $builder = $reflection->newInstanceWithoutConstructor();
        $builder->paramTypes = $types;
        $reflection->getProperty('permissionChecker')->setValue($builder, new PermissionChecker());

        $field = $reflection->getMethod('createDynamicFieldConfig')->invoke($builder, 'S2_ADMIN_COLOR');
        self::assertInstanceOf(FieldConfig::class, $field);
        self::assertCount(1, $field->validators);

        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $validator = $field->validators[0];

        self::assertSame([], $validator->getValidationErrors('#A1b2C3', $translator));
        self::assertSame(['Invalid admin color'], $validator->getValidationErrors('red;body{}', $translator));
    }
}
