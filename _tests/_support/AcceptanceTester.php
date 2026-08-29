<?php

declare(strict_types = 1);

use Codeception\Actor;


/**
 * Inherited Methods
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause()
 *
 * @SuppressWarnings(PHPMD)
 */
class AcceptanceTester extends Actor
{
    use _generated\AcceptanceTesterActions;

    /**
     * Define custom actions here
     */

    public function install(string $userName, string $userPass, string $dbType, string $dbUser, string $dbPassword): void
    {
        $I = $this;
        $I->amOnPage('/');
        $I->seeLink('Run Installation', '/_admin/install.php');
        $I->amOnPage('/_admin/install.php');
        $I->seeResponseCodeIs(200);
        $I->see('Register 2.0dev', 'h1');

        $I->haveHttpHeader('Origin', 'https://attacker.example');
        $I->sendAjaxPostRequest('/_admin/install.php?lang=English', ['req_language' => 'English']);
        $I->seeResponseCodeIs(403);
        $I->see('This installation request came from another site and was rejected.');
        $I->unsetHttpHeader('Origin');
        $I->amOnPage('/_admin/install.php');

        $I->selectOption('req_db_type', $dbType);
        $I->fillField('req_db_host', '127.0.0.1'); // not localhost for Github Actions
        $I->fillField('req_db_name', 'register_test');
        $I->fillField('db_username', $dbUser);
        $I->fillField('db_password', $dbPassword);
        $I->fillField('req_username', $userName);
        $I->fillField('req_password', $userPass);
        $I->click('start');
        $I->canSeeResponseCodeIs(200);
        $I->see('Register is completely installed!');

        $configFileName = __DIR__ . '/../../config.test.php';
        $config = include $configFileName;
        if (!\is_array($config)) {
            throw new \RuntimeException('Unable to read config.test.php');
        }

        // We need '/index.php?' prefix to test file-like URLs such as /sitemap.xml.
        $config['http']['url_prefix'] = '/index.php?';
        $security = $config['security'] ?? [];
        if (!\is_array($security)) {
            throw new \RuntimeException('Unable to read security configuration from config.test.php');
        }

        $security['secret_file'] = '_tests/_output/config.acceptance.secrets.php';
        $config['security']      = $security;

        // The acceptance application must never traverse a developer's real media or backup directories.
        // Keeping both stores inside the disposable test output also makes the background backup deterministic.
        $files = $config['files'] ?? [];
        if (!\is_array($files)) {
            throw new \RuntimeException('Unable to read file storage configuration from config.test.php');
        }

        $files['image_dir'] = '_tests/_output/media';
        $config['files']    = $files;

        $backups = $config['backups'] ?? [];
        if (!\is_array($backups)) {
            throw new \RuntimeException('Unable to read backup configuration from config.test.php');
        }

        $backups['directory'] = '_tests/_output/backups';
        $config['backups']    = $backups;

        file_put_contents($configFileName, '<?php return ' . \var_export($config, true) . ';');
    }

    public function canWriteComment(
        bool $premoderation = false,
        string $text = 'This is my first comment! 👪🐶',
        string $email = 'roman@example.com',
        string $name = 'Roman 🌞',
        bool $subscribed = true,
    ): void
    {
        $I = $this;

        $I->clearEmails();
        $I->fillField('#comment-name', $name);
        $I->fillField('#comment-email', $email);
        if ($subscribed) {
            $I->checkOption('#subscribed');
        }

        $I->fillField('#comment-text', $text);
        $I->click('submit');

        $I->seeResponseCodeIs(200);
        $I->see('Check your email');

        $emails = $I->waitForEmails(1);
        $I->assertCount(1, $emails);
        $callbackUrl = $this->emailCallbackUrl($emails[0]);

        // Leave only notifications emitted while the verified comment is created.
        $I->clearEmails();
        $I->amOnPage($callbackUrl);
        $I->seeResponseCodeIs(200);

        if ($premoderation) {
            $I->dontSee($text);
        } else {
            $I->see($name, '.comment-name');
            $I->see($text);
        }
    }

    private function emailCallbackUrl(string $rawMessage): string
    {
        $decoded = html_entity_decode(
            quoted_printable_decode($rawMessage),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
        if (preg_match(
            '~https?://[^\s<>"\']*/auth/email/callback(?:\?|&)token=[A-Za-z0-9_-]{40,100}~',
            $decoded,
            $matches,
        ) !== 1) {
            throw new RuntimeException('The captured email contains no comment verification callback URL.');
        }

        return $matches[0];
    }

    public function sendComment(string $name, string $email, string $text): void
    {
        $I = $this;

        // Authenticated readers comment under their account identity, so the public
        // form intentionally contains no editable name or email fields for them.
        if ($I->grabMultiple('#comment-name') !== []) {
            $I->fillField('#comment-name', $name);
        }

        if ($I->grabMultiple('#comment-email') !== []) {
            $I->fillField('#comment-email', $email);
        }

        $I->fillField('#comment-text', $text);
        $I->click('submit');
    }

    public function login(string $username = 'admin', string $userpass = ''): void
    {
        $I = $this;

        $I->amOnPage('/_admin/index.php');
        $I->canSee('Username');
        $I->canSee('Password');

        $I->sendAjaxPostRequest('/_admin/index.php?action=login', [
            'login'     => $username,
            'pass'      => $userpass,
        ]);
    }

    public function installExtension(string $extensionId): void
    {
        $I = $this;
        $I->amOnPage('/_admin/index.php?entity=Extension');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.extension.available [title=' . $extensionId . ']');
        $I->dontSeeElement('.extension.enabled [title=' . $extensionId . ']');

        $I->sendAjaxPostRequest('/_admin/ajax.php?action=install_extension&id=' . $extensionId, [
            'csrf_token' => $I->grabAttributeFrom('[data-id=' . $extensionId . ']', 'data-csrf-token'),
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/_admin/index.php?entity=Extension');
        $I->dontSeeElement('.extension.available [title=' . $extensionId . ']');
        $I->seeElement('.extension.enabled [title=' . $extensionId . ']');
    }

    public function changeSetting(string $paramName, int|string|bool $value): void
    {
        $I = $this;

        $I->amOnPage('/_admin/index.php?entity=Config&action=list');
        $I->seeResponseCodeIsSuccessful();

        $I->submitForm('form[action="?entity=Config&action=patch&field=value&name=' . $paramName . '"]', [
            'value' => $value,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    public function clearEmails(): void
    {
        $fi = new FilesystemIterator($this->getEmailDir(), FilesystemIterator::SKIP_DOTS);
        foreach ($fi as $f) {
            $filePath = $f instanceof SplFileInfo ? $f->getPathname() : $f;
            unlink($filePath);
        }
    }

    /** Lets ordinary web requests drain enough due background jobs for a negative assertion. */
    public function drainQueue(int $requests = 20): void
    {
        for ($attempt = 0; $attempt < $requests; ++$attempt) {
            $this->amOnPage('/index.php?/robots.txt');
        }
    }

    /**
     * @return string[]
     */
    public function waitForEmails(int $expectedCount, int $requests = 20): array
    {
        for ($attempt = 0; $attempt < $requests; ++$attempt) {
            $emails = $this->getEmails();
            if (\count($emails) >= $expectedCount) {
                return $emails;
            }

            $this->amOnPage('/index.php?/robots.txt');
        }

        return $this->getEmails();
    }

    /**
     * @return string[]
     */
    public function getEmails(): array
    {
        $result = [];
        $fi     = new FilesystemIterator($this->getEmailDir(), FilesystemIterator::SKIP_DOTS);
        foreach ($fi as $f) {
            $filePath = $f instanceof SplFileInfo ? $f->getPathname() : $f;
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                throw new RuntimeException('Unable to read a captured email.');
            }

            $result[] = $contents;
        }

        return $result;
    }

    private function getEmailDir(): string
    {
        return '_tests/_output/email';
    }
}
