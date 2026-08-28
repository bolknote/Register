<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Admin\Security;

use Codeception\Test\Unit;
use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Config\VirtualFieldType;
use Register\AdminYard\Database\Key;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Database\TypeTransformer;
use Register\AdminYard\Event\BeforeSaveEvent;
use Register\AdminYard\Translator;
use Register\Admin\Security\ReauthenticationAdminConfigExtender;
use Register\Core\Model\AuthManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ReauthenticationAdminConfigExtenderTest extends Unit
{
    public function testAddsANonPersistentCurrentPasswordFieldToUserForms(): void
    {
        $adminConfig = $this->adminConfig();
        $this->extender(
            self::createStub(AuthManager::class),
            new RequestStack(),
        )->extend($adminConfig);

        $field = $adminConfig->findEntityByName('User')?->findFieldByName('current_password');
        self::assertInstanceOf(FieldConfig::class, $field);
        self::assertInstanceOf(VirtualFieldType::class, $field->type);
        self::assertTrue($field->allowedOnAction(FieldConfig::ACTION_NEW));
        self::assertTrue($field->allowedOnAction(FieldConfig::ACTION_EDIT));
        self::assertFalse($field->allowedOnAction(FieldConfig::ACTION_LIST));
    }

    public function testSensitiveUserUpdateRejectsAnInvalidActorPassword(): void
    {
        $request = Request::create('/_admin/', Request::METHOD_POST, [
            'current_password' => 'wrong-password',
        ]);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $authManager = $this->createMock(AuthManager::class);
        $authManager
            ->expects(self::once())
            ->method('verifyCurrentPassword')
            ->with($request, 'wrong-password')
            ->willReturn(false);
        $adminConfig = $this->adminConfig();
        $this->extender(
            $authManager,
            $requestStack,
        )->extend($adminConfig);

        $context = ['security_changed_fields' => ['edit_users']];
        $event = new BeforeSaveEvent(
            $this->dataProvider(),
            ['edit_users' => true, 'current_password' => 'wrong-password'],
            $context,
            new Key(['id' => 42]),
        );
        $this->dispatch($adminConfig, 'User', EntityConfig::EVENT_BEFORE_UPDATE, $event);

        self::assertSame(['Localized invalid password'], $event->errorMessages);
        self::assertArrayNotHasKey('current_password', $event->data);
    }

    public function testOrdinaryUserDataDoesNotRequireTheActorPassword(): void
    {
        $request = Request::create('/_admin/', Request::METHOD_POST, [
            'current_password' => 'actor-password',
        ]);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $authManager = $this->createMock(AuthManager::class);
        $authManager
            ->expects(self::never())
            ->method('verifyCurrentPassword');
        $adminConfig = $this->adminConfig();
        $this->extender($authManager, $requestStack)->extend($adminConfig);

        $ordinaryContext = ['security_changed_fields' => []];
        $ordinaryEvent = new BeforeSaveEvent(
            $this->dataProvider(),
            ['email' => 'author@example.test', 'current_password' => 'actor-password'],
            $ordinaryContext,
            new Key(['id' => 42]),
        );
        $this->dispatch($adminConfig, 'User', EntityConfig::EVENT_BEFORE_UPDATE, $ordinaryEvent);
        self::assertSame([], $ordinaryEvent->errorMessages);
        self::assertArrayNotHasKey('current_password', $ordinaryEvent->data);
    }

    private function adminConfig(): AdminConfig
    {
        $adminConfig = new AdminConfig();
        $adminConfig->addEntity(new EntityConfig('User', 'users'));
        $adminConfig->addEntity(new EntityConfig('Config', 'config'));

        return $adminConfig;
    }

    private function extender(
        AuthManager $authManager,
        RequestStack $requestStack,
    ): ReauthenticationAdminConfigExtender {
        return new ReauthenticationAdminConfigExtender(
            $authManager,
            $requestStack,
            new Translator([
                'Invalid current password' => 'Localized invalid password',
            ], 'en'),
        );
    }

    private function dataProvider(): PdoDataProvider
    {
        return new PdoDataProvider(new \PDO('sqlite::memory:'), new TypeTransformer());
    }

    private function dispatch(
        AdminConfig $adminConfig,
        string $entityName,
        string $eventName,
        BeforeSaveEvent $event,
    ): void {
        $listeners = $adminConfig->findEntityByName($entityName)?->getListeners()[$entityName . '.' . $eventName] ?? [];
        foreach ($listeners as $listener) {
            $listener($event);
        }
    }
}
