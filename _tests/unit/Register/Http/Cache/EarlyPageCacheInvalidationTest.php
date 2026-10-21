<?php

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;
use Register\Content\ContentId;
use Register\Core\Http\Cache\EarlyPageCache;
use Register\Core\Pdo\PDO;
use Register\Module\Blog\Model\BlogPageCache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

final class EarlyPageCacheInvalidationTest extends TestCase
{
    private string $directory = '';

    private ?EarlyPageCache $early = null;

    private ?BlogPageCache $blog = null;

    private ?PDO $pdo = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-early-transaction-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->early = $this->fresh();
        $this->pdo = new PDO('sqlite::memory:');
        $this->blog = new BlogPageCache(new ArrayAdapter(), pdo: $this->pdo, earlyPageCache: $this->early);
        self::assertTrue($this->publish($this->early()));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    private function early(): EarlyPageCache
    {
        return $this->early ?? throw new \LogicException('The cache fixture has not been initialized.');
    }

    private function blog(): BlogPageCache
    {
        return $this->blog ?? throw new \LogicException('The blog-cache fixture has not been initialized.');
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? throw new \LogicException('The PDO fixture has not been initialized.');
    }

    public function testCommentMutationAndRollbackRetireIntermediateSnapshots(): void
    {
        $this->pdo()->beginTransaction();
        $this->blog()->invalidateCommentChange(ContentId::post(1));
        self::assertNull($this->early()->lookup($this->request()));
        $intermediate = $this->fresh();
        self::assertTrue($this->publish($intermediate));
        $this->pdo()->rollBack();
        self::assertNull($intermediate->lookup($this->request()));
        self::assertFalse($this->publish($intermediate));
    }

    public function testCommitRetiresAnOlderConcurrentRender(): void
    {
        $this->pdo()->beginTransaction();
        $this->blog()->invalidateContentChange(ContentId::post(1));
        $intermediate = $this->fresh();
        self::assertTrue($this->publish($intermediate));
        $this->pdo()->commit();
        self::assertNull($intermediate->lookup($this->request()));
        self::assertFalse($this->publish($intermediate));
    }

    public function testImportedCommentsWaitForCommitAndDoNothingOnRollback(): void
    {
        $this->pdo()->beginTransaction();
        $this->blog()->invalidateCommentChange(ContentId::post(1), deferUntilCommit: true);
        self::assertNotNull($this->early()->lookup($this->request()));
        $this->pdo()->rollBack();
        self::assertNotNull($this->early()->lookup($this->request()));
        $this->pdo()->beginTransaction();
        $this->blog()->invalidateCommentChange(ContentId::post(1), deferUntilCommit: true);
        $this->pdo()->commit();
        self::assertNull($this->early()->lookup($this->request()));
    }

    private function fresh(): EarlyPageCache
    {
        return new EarlyPageCache($this->directory . '/cache', $this->directory);
    }

    private function publish(EarlyPageCache $cache): bool
    {
        return $cache->store($this->request(), ['content-type' => ['text/html'], 'x-register-page-cache' => ['hit']], '<p>Public</p>');
    }

    /** @return array<string,string> */
    private function request(): array
    {
        return ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/example', 'HTTP_HOST' => 'example.test'];
    }
}
