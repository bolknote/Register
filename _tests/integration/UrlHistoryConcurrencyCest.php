<?php

declare(strict_types = 1);

namespace integration;

use Register\AdminYard\Config\DbColumnFieldType;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Database\TypeTransformer;
use Register\AdminYard\Form\Form;
use Register\AdminYard\Form\FormControlFactory;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\Form\FormParams;
use Register\AdminYard\SettingStorage\SessionSettingStorage;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Core\Pdo\DbLayerException;
use Register\Extension\activitypub\Admin\ActivityPubContentEditorControllerFactory;
use Register\Url\UrlHistoryService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Real two-connection RR coverage; the suite's rollback-only connection is untouched. */
final class UrlHistoryConcurrencyCest
{
    public function mysqlEditorialTransactionLocksBeforeTheFirstSnapshotRead(\IntegrationTester $I, \Codeception\Scenario $scenario): void
    {
        if ($I->grabService(\PDO::class)->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $scenario->skip('MySQL REPEATABLE READ regression; other drivers have the portable unit coverage.');
            return;
        }

        $first = $I->createApplication()->container;
        $second = $I->createApplication()->container;
        $pdo = $first->get(\PDO::class);
        $otherPdo = $second->get(\PDO::class);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $otherPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $otherPdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $history = $first->get(UrlHistoryService::class);
        $otherHistory = $second->get(UrlHistoryService::class);
        $data = new PdoDataProvider($pdo, new TypeTransformer());
        $translator = new Translator([], 'en');
        $entity = new EntityConfig('Article', 'content');
        $entity->addField(new FieldConfig('id', type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT, true), useOnActions: []));

        $formFactory = new UrlHistorySnapshotProbeFormFactory(static function () use ($I, $pdo, $otherHistory): never {
            $I->assertTrue($pdo->inTransaction());
            // This represents the first form/BeforeSave SELECT that creates a fixed RR snapshot.
            $statement = $pdo->query("SELECT value FROM config WHERE name = 'REGISTER_SCHEMA_GENERATION'");
            if ($statement === false) {
                throw new \RuntimeException('Unable to establish the editorial snapshot.');
            }

            $statement->fetchColumn();
            $blocked = false;
            try {
                $otherHistory->run(static fn(): null => null);
            } catch (DbLayerException $exception) {
                $cause = $exception->getPrevious();
                $I->assertInstanceOf(\PDOException::class, $cause);
                $I->assertSame(1205, (int)($cause->errorInfo[1] ?? 0));
                $blocked = true;
            }

            $I->assertTrue($blocked, 'Another editor must not rename content after this snapshot is established.');
            // Do not persist any writes, including the mutex's no-op UPDATE.
            throw new \RuntimeException('URL snapshot probe complete');
        }, new FormControlFactory(), $translator, $data);
        $controller = (new ActivityPubContentEditorControllerFactory($history))->create(
            $entity, new EventDispatcher(), $data, new ViewTransformer(), $translator,
            new TemplateRenderer($translator), $formFactory,
            new SessionSettingStorage(new Session(new MockArraySessionStorage())),
        );

        foreach (['editAction', 'newAction'] as $action) {
            try {
                if ($action === 'editAction') {
                    $controller->editAction(Request::create('/?id=1', 'POST'));
                } else {
                    $controller->newAction(Request::create('/', 'POST'));
                }

                $I->fail('The probe must stop the request before rendering.');
            } catch (\RuntimeException $exception) {
                $I->assertSame('URL snapshot probe complete', $exception->getMessage());
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($otherPdo->inTransaction()) {
                    $otherPdo->rollBack();
                }
            }

            $I->assertFalse($pdo->inTransaction());
            $I->assertFalse($otherPdo->inTransaction());
        }
    }
}

/** Stops at the exact point where the real editor first starts reading form data. */
final readonly class UrlHistorySnapshotProbeFormFactory extends FormFactory
{
    /** @param \Closure(): never $probe */
    public function __construct(
        private \Closure $probe,
        FormControlFactory $controls,
        Translator $translator,
        PdoDataProvider $data,
    ) {
        parent::__construct($controls, $translator, $data);
    }

    #[\Override]
    public function createEntityForm(FormParams $formParams): Form
    {
        ($this->probe)();
    }
}
