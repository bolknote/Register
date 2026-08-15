<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Security;

use Codeception\Test\Unit;
use S2\AdminYard\Form\Form;
use S2\AdminYard\Form\FormParams;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\Translator;
use Symfony\Component\HttpFoundation\Request;

final class AdminYardFormParamsTest extends Unit
{
    public function testDerivesTokensWithHmacSha256(): void
    {
        $storage = new SecurityTestSettingStorage(['main_csrf_token' => '0123456789abcdef0123456789abcdef']);
        $params  = new FormParams('Article', [], $storage, 'delete', ['id' => '42']);

        $expected = hash_hmac('sha256', serialize([
            'Article',
            'delete',
            [],
            ['id' => '42'],
        ]), '0123456789abcdef0123456789abcdef');

        self::assertSame($expected, $params->getCsrfToken());
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $params->getCsrfToken());
    }

    public function testRejectsCorruptStoredSecret(): void
    {
        $storage = new SecurityTestSettingStorage(['main_csrf_token' => null]);

        $this->expectException(\UnexpectedValueException::class);
        (new FormParams('Article', [], $storage, 'delete'))->getCsrfToken();
    }

    public function testFormUsesConstantTimeTokenChecksAndHmacTemporaryTokens(): void
    {
        $realToken = str_repeat('a', 64);
        $tempToken = Form::generateTempCsrfToken($realToken);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}-[0-9a-f]{32}-[0-9]{1,10}\z/D', $tempToken);

        $form = new Form(new Translator([], 'en'));
        $form->setCsrfToken($realToken);
        $form->submit(Request::create(
            '/_admin/index.php',
            Request::METHOD_POST,
            ['__csrf_token' => 'stale-token'],
            server: ['HTTP_X_ADMINYARD_CSRF_TOKEN' => $tempToken],
        ));
        self::assertTrue($form->isCsrfCheckPassed());

        $tamperedForm = new Form(new Translator([], 'en'));
        $tamperedForm->setCsrfToken($realToken);
        $tamperedForm->submit(Request::create(
            '/_admin/index.php',
            Request::METHOD_POST,
            ['__csrf_token' => ['unexpected-array']],
            server: ['HTTP_X_ADMINYARD_CSRF_TOKEN' => '0' . substr($tempToken, 1)],
        ));
        self::assertFalse($tamperedForm->isCsrfCheckPassed());
    }
}

/** @internal */
final class SecurityTestSettingStorage implements SettingStorageInterface
{
    /** @param array<string, array<mixed>|string|int|float|bool|null> $values */
    public function __construct(private array $values = [])
    {
    }

    #[\Override]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<mixed>|string|int|float|bool|null */
    #[\Override]
    public function get(string $key): array|string|int|float|bool|null
    {
        return $this->values[$key] ?? null;
    }

    /** @param array<mixed>|string|int|float|bool|null $data */
    #[\Override]
    public function set(string $key, array|string|int|float|bool|null $data): void
    {
        $this->values[$key] = $data;
    }

    #[\Override]
    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }
}
