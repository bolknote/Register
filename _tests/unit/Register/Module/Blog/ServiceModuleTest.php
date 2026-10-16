<?php

declare(strict_types = 1);

namespace unit\Register\Module\Blog;

use Codeception\Test\Unit;
use Register\Core\Framework\Container;
use Register\Module\Blog\Inplace\PostInplaceController;
use Register\Module\Blog\ServiceModule;

final class ServiceModuleTest extends Unit
{
    public function testInplaceEditingRequiresTransactionAwarePdo(): void
    {
        $container = new Container([]);
        $container->set(\PDO::class, new \PDO('sqlite::memory:'));
        (new ServiceModule())->buildContainer($container);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("In-place editing requires Register's transaction-aware PDO service.");
        $container->get(PostInplaceController::class);
    }
}
