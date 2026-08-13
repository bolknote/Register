<?php

declare(strict_types = 1);

/**
 * Inherited Methods
 * @method void wantTo($text)
 * @method void wantToTest($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause($vars = [])
 *
 * @SuppressWarnings(PHPMD)
 */
class IntegrationTester extends \Codeception\Actor
{
    use _generated\IntegrationTesterActions;

    public function login(string $username = 'admin', string $userpass = ''): void
    {
        $I = $this;
        $I->amOnPage('https://localhost/_admin/index.php');
        $I->canSee('Username');
        $I->canSee('Password');

        $I->sendPost('https://localhost/_admin/index.php?action=login', [
            'login'     => $username,
            'pass'      => $userpass,
        ]);
    }

    public function logout(): void
    {
        $this->amOnPage('https://localhost/_admin/index.php?action=logout');
    }

    /** @param list<int|string> $path */
    public function assertJsonSubResponseContains(string $needle, array $path): void
    {
        $I        = $this;
        $response = $I->grabJson();
        foreach ($path as $value) {
            $I->assertArrayHasKey($value, $response);
            $response = $response[$value];
        }

        $I->assertStringContainsString($needle, $response);
    }

    /** @param list<int|string> $path */
    public function assertJsonResponseHasNoKey(array $path): void
    {
        $I        = $this;
        $response = $I->grabJson();
        $total    = count($path);
        foreach ($path as $index => $value) {
            if ($index === $total - 1) {
                $I->assertArrayNotHasKey($value, $response);
            } else {
                $I->assertArrayHasKey($value, $response);
                $response = $response[$value];
            }
        }
    }

    /** @param list<int|string> $path */
    public function assertJsonSubResponseEquals(mixed $needle, array $path): void
    {
        $I        = $this;
        $response = $I->grabJson();
        foreach ($path as $value) {
            $I->assertArrayHasKey($value, $response);
            $response = $response[$value];
        }

        $I->assertEquals($needle, $response);
    }
}
