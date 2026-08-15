<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\AdminYard;

use Codeception\Test\Unit;
use S2\AdminYard\Translator;
use S2\Cms\AdminYard\Form\CustomFormControlFactory;
use S2\Cms\AdminYard\Form\SafePassword;

final class SafePasswordTest extends Unit
{
    public function testSubmittedCredentialIsNeverRenderedBackIntoHtml(): void
    {
        $control = new SafePassword('current_password');
        $control->setPostValue('correct horse battery staple');

        $html = $control->getHtml('current-password-control');

        self::assertStringNotContainsString('correct horse battery staple', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
        self::assertStringContainsString('maxlength="255"', $html);
        self::assertStringContainsString('id="current-password-control"', $html);
    }

    public function testFactoryUsesSafeControlForPasswordsAndApiSecrets(): void
    {
        $factory = new CustomFormControlFactory(new Translator([], 'en'));

        self::assertInstanceOf(SafePassword::class, $factory->create('password', 'password'));
        $secretControl = $factory->create('password', 'value');
        self::assertInstanceOf(SafePassword::class, $secretControl);
        self::assertStringContainsString('autocomplete="off"', $secretControl->getHtml());
        self::assertStringNotContainsString('maxlength=', $secretControl->getHtml());
    }
}
