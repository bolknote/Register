<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Update;

use Codeception\Test\Unit;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Module\Blog\Model\CachedBlogResponse;
use Register\Module\Blog\Model\PostFeed;
use Register\Update\GeneratedAssetCacheCleaner;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

final class GeneratedAssetCacheCleanerTest extends Unit
{
    private string $temporaryRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_asset_cache_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryRoot . '/_cache/nested', 0700, true);
        mkdir($this->temporaryRoot . '/_cache/pages', 0700, true);
        mkdir($this->temporaryRoot . '/_cache/pages_v2', 0700, true);
        mkdir($this->temporaryRoot . '/_cache/response_encoding', 0700, true);
        mkdir($this->temporaryRoot . '/_cache/recommendations', 0700, true);
        mkdir($this->temporaryRoot . '/_cache/register-updates/session', 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryRoot);
    }

    public function testRemovesGeneratedAssetsAndKeepsBoundaryFiles(): void
    {
        file_put_contents($this->temporaryRoot . '/_cache/.htaccess', 'deny');
        file_put_contents($this->temporaryRoot . '/_cache/index.html', '');
        file_put_contents($this->temporaryRoot . '/_cache/site.css', 'old');
        file_put_contents($this->temporaryRoot . '/_cache/site.css.meta.php', 'old');
        file_put_contents($this->temporaryRoot . '/_cache/nested/site.js', 'old');
        file_put_contents($this->temporaryRoot . '/_cache/pages/item', 'rendered page');
        file_put_contents($this->temporaryRoot . '/_cache/pages_v2/item', 'next rendered page');
        file_put_contents($this->temporaryRoot . '/_cache/response_encoding/item', 'compressed response');
        file_put_contents($this->temporaryRoot . '/_cache/recommendations/item', 'expensive result');
        file_put_contents($this->temporaryRoot . '/_cache/register-updates/session/state.json', '{"status":"migrating"}');
        file_put_contents($this->temporaryRoot . '/_cache/performance.jsonl', '{"duration_ms":1200}');
        file_put_contents($this->temporaryRoot . '/_cache/query-profiler.jsonl', '{"query_count":1}');
        file_put_contents($this->temporaryRoot . '/_cache/query-profiler-state.json', '{"expires_at":1}');
        file_put_contents($this->temporaryRoot . '/_cache/app.log', 'diagnostic');
        file_put_contents($this->temporaryRoot . '/_cache/picture-upload-quota.lock', 'lock');

        (new GeneratedAssetCacheCleaner($this->temporaryRoot))->clear();

        self::assertFileExists($this->temporaryRoot . '/_cache/.htaccess');
        self::assertFileExists($this->temporaryRoot . '/_cache/index.html');
        self::assertFileDoesNotExist($this->temporaryRoot . '/_cache/site.css');
        self::assertFileDoesNotExist($this->temporaryRoot . '/_cache/site.css.meta.php');
        self::assertDirectoryDoesNotExist($this->temporaryRoot . '/_cache/nested');
        self::assertFileExists($this->temporaryRoot . '/_cache/pages/item');
        self::assertFileExists($this->temporaryRoot . '/_cache/pages_v2/item');
        self::assertFileExists($this->temporaryRoot . '/_cache/response_encoding/item');
        self::assertFileExists($this->temporaryRoot . '/_cache/recommendations/item');
        self::assertFileExists($this->temporaryRoot . '/_cache/register-updates/session/state.json');
        self::assertFileExists($this->temporaryRoot . '/_cache/performance.jsonl');
        self::assertFileExists($this->temporaryRoot . '/_cache/query-profiler.jsonl');
        self::assertFileExists($this->temporaryRoot . '/_cache/query-profiler-state.json');
        self::assertFileExists($this->temporaryRoot . '/_cache/app.log');
        self::assertFileExists($this->temporaryRoot . '/_cache/picture-upload-quota.lock');
    }

    public function testPrebuiltAssetTransitionDiscardsOldHtmlWithoutDiscardingContentFragments(): void
    {
        $oldAsset = $this->temporaryRoot . '/_cache/site.deadbeef.css';
        file_put_contents($oldAsset, 'old runtime CSS');
        $oldHtml = '<link rel="stylesheet" href="/_cache/site.deadbeef.css">';
        $oldResponse = CachedBlogResponse::fromResponse(new Response($oldHtml));
        self::assertNotNull($oldResponse);
        $oldContentResponse = CachedBlogResponse::fromResponse(new Response($oldHtml), '0123456789abcdef');
        self::assertNotNull($oldContentResponse);
        $pool = new ArrayAdapter();
        $pool->get('register_content_response_generation_v1', static fn(): string => '0123456789abcdef');

        $variants = ['full_bot', 'full_new_visitor', 'full_known_visitor',
            'partial_bot', 'partial_new_visitor', 'partial_known_visitor'];
        foreach ($variants as $variant) {
            $oldVariant = str_ends_with($variant, '_bot') ? $variant . '_noninteractive_v2' : $variant;
            // These are the complete HTML snapshots written before build-time assets.
            $pool->get('register_blog_first_response_v4_' . $oldVariant, static fn(): CachedBlogResponse => $oldResponse);
            $pool->get('register_blog_all_response_v4_' . $oldVariant, static fn(): CachedBlogResponse => $oldResponse);
            $pool->get('register_content_response_v4_' . hash('sha256', '/fixture') . '_' . $oldVariant,
                static fn(): CachedBlogResponse => $oldContentResponse);
        }

        $feed = new PostFeed('warm content fragment', null, null);
        (new BlogPageCache($pool))->firstPage(static fn(): PostFeed => $feed);
        (new GeneratedAssetCacheCleaner($this->temporaryRoot))->clear();
        self::assertFileDoesNotExist($oldAsset);

        $cache = new BlogPageCache($pool);
        self::assertSame($feed->html, $cache->firstPage(static fn(): PostFeed => new PostFeed('cold content fragment', null, null))->html);
        $newHtml = '<link rel="stylesheet" href="/_styles/example/site.css.asset?v=' . str_repeat('a', 64) . '">';
        foreach ($variants as $variant) {
            self::assertSame($newHtml, $cache->firstResponse($variant, static fn(): Response => new Response($newHtml))->getContent());
            self::assertSame($newHtml, $cache->allResponse($variant, static fn(): Response => new Response($newHtml))->getContent());
            self::assertSame($newHtml, $cache->contentResponse($variant, '/fixture', static fn(): Response => new Response($newHtml))->getContent());
        }
    }
}
