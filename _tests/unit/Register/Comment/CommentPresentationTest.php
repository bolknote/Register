<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Comment;

use Codeception\Test\Unit;
use Register\Comment\CommentPresentationEnricherInterface;
use Register\Comment\CommentPresentationEnrichment;
use Register\Comment\ContentCommentRenderer;
use Register\Content\ContentId;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Model\AuthProvider;
use Register\Model\Comment\CommentModerationTokenManager;
use Register\Core\Model\Comment\CommentThreadBuilder;
use Register\Model\Comment\CommentThreadRenderer;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CommentPresentationTest extends Unit
{
    public function testBatchEnrichmentRendersOnlyLocalAvatarAndExplicitHttpsProvenance(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dbLayer = new DbLayerSqlite($pdo, '');
        $this->createTables($pdo);
        $pdo->exec("INSERT INTO comments (
            id, content_type, content_id, parent_id, userpic_id, time, modify_time, nick, email,
            good, text, shown, deleted
        ) VALUES (42, 'post', 7, NULL, NULL, 1700000000, 0, 'Remote Alice', '', 0,
            'A federated reply.', 1, 0)");

        $urlBuilder = new UrlBuilder('/register', '', '');
        $viewer = new Viewer(
            $this->translator(),
            $urlBuilder,
            dirname(__DIR__, 4) . '/',
            $this->styleProxy(),
            false,
        );
        $threadRenderer = new CommentThreadRenderer(
            $viewer,
            new CommentThreadBuilder(),
            new CommentModerationTokenManager(new SpamIdentityHasher(str_repeat('s', 32))),
            $urlBuilder,
            '/pictures',
        );
        $enricher = new class implements CommentPresentationEnricherInterface {
            /**
             * @param non-empty-list<int> $commentIds
             * @return list<CommentPresentationEnrichment>
             */
            #[\Override]
            public function enrich(array $commentIds): array
            {
                if ($commentIds !== [42]) {
                    throw new \LogicException('The renderer did not batch the expected comment identifiers.');
                }

                return [new CommentPresentationEnrichment(
                    42,
                    '/activitypub/media/Abcdefghijklmnopqrstuv',
                    'https://remote.example/users/alice',
                    'https://remote.example/notes/reply-1',
                    'ActivityPub',
                )];
            }
        };
        $renderer = new ContentCommentRenderer(
            $dbLayer,
            $threadRenderer,
            new AuthProvider($dbLayer, 'test'),
            $enricher,
        );

        $html = $renderer->render(ContentId::post(7), Request::create('/post/7'), '/post/7');
        self::assertStringContainsString(
            '<img src="/register/activitypub/media/Abcdefghijklmnopqrstuv"',
            $html,
        );
        self::assertStringContainsString('href="https://remote.example/users/alice"', $html);
        self::assertStringContainsString('href="https://remote.example/notes/reply-1"', $html);
        self::assertStringContainsString('>ActivityPub</a>', $html);
        self::assertStringNotContainsString('remote.example/media/avatar', $html);
        self::assertSame(2, substr_count($html, 'rel="nofollow ugc noopener noreferrer"'));
    }

    public function testPresentationValueRejectsRemoteOrAmbiguousAvatarPaths(): void
    {
        foreach ([
            'https://remote.example/avatar.png',
            '//remote.example/avatar.png',
            '/activitypub/media/valid?remote=https://remote.example',
            '/activitypub/../secret',
        ] as $path) {
            $wasRejected = false;

            try {
                new CommentPresentationEnrichment(1, $path);
            } catch (\InvalidArgumentException) {
                $wasRejected = true;
            }

            self::assertTrue($wasRejected, 'An ambiguous or remote comment avatar path must be rejected.');
        }
    }

    public function testRegisteredAuthorEmailIsMatchedCaseInsensitively(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dbLayer = new DbLayerSqlite($pdo, '');
        $this->createTables($pdo);
        $pdo->exec("INSERT INTO users (email) VALUES ('Author@Example.test')");
        $pdo->exec("INSERT INTO comments (
            id, content_type, content_id, parent_id, userpic_id, time, modify_time, nick, email,
            good, text, shown, deleted
        ) VALUES (43, 'post', 8, NULL, NULL, 1700000000, 0, 'Author', 'author@example.test', 0,
            'An author reply.', 1, 0)");

        $urlBuilder = new UrlBuilder('/register', '', '');
        $viewer = new Viewer(
            $this->translator(),
            $urlBuilder,
            dirname(__DIR__, 4) . '/',
            $this->styleProxy(),
            false,
        );
        $renderer = new ContentCommentRenderer(
            $dbLayer,
            new CommentThreadRenderer(
                $viewer,
                new CommentThreadBuilder(),
                new CommentModerationTokenManager(new SpamIdentityHasher(str_repeat('s', 32))),
                $urlBuilder,
                '/pictures',
            ),
            new AuthProvider($dbLayer, 'test'),
        );

        $html = $renderer->render(ContentId::post(8), Request::create('/post/8'), '/post/8');
        self::assertStringContainsString('class="comment-author-mark"', $html);
        self::assertStringContainsString('class="comment-item depth-0 by-author', $html);
    }

    private function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE comments (
            id INTEGER PRIMARY KEY,
            content_type TEXT NOT NULL,
            content_id INTEGER NOT NULL,
            parent_id INTEGER,
            user_id INTEGER,
            userpic_id INTEGER,
            time INTEGER NOT NULL,
            modify_time INTEGER NOT NULL,
            nick TEXT NOT NULL,
            email TEXT NOT NULL,
            good INTEGER NOT NULL,
            text TEXT NOT NULL,
            shown INTEGER NOT NULL,
            deleted INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE users (email TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE userpics (id INTEGER PRIMARY KEY, storage_key TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE spam_assessments (
            id INTEGER PRIMARY KEY,
            target_type TEXT NOT NULL,
            comment_id INTEGER NOT NULL,
            moderator_label TEXT
        )');
        $pdo->exec('CREATE TABLE register_reaction_aggregate (
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            emoji TEXT NOT NULL,
            reaction_count INTEGER NOT NULL
        )');
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn(string $id): string => match ($id) {
            'Time format' => 'Y-m-d H:i',
            'locale'      => 'en',
            default       => $id,
        });

        return $translator;
    }

    private function styleProxy(): \Register\Core\Config\StringProxy
    {
        $provider = new DynamicConfigProvider();
        $reflection = new \ReflectionClass($provider);
        $reflection->getProperty('params')->setValue($provider, ['REGISTER_STYLE' => 'register']);

        return $provider->getStringProxy('REGISTER_STYLE');
    }
}
