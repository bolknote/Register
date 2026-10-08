<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Core\Pdo\DbLayer;
use Register\Module\Search\Service\SearchDocumentFactory;
use Register\Rose\Indexer;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/** @group search */
final class SearchPresentationCest
{
    public function testOutOfRangePagesReturnTheActualFirstPage(\IntegrationTester $I): void
    {
        $ids = [];
        try {
            for ($i = 0; $i < 3; ++$i) {
                $ids[] = $this->insertAndIndexContent($I, ContentType::POST, 'Page fixture ' . $i, 'search-page-fixture-' . $i, '<p>pageboundarymarker</p>');
            }

            $I->setConfigValue('REGISTER_MAX_ITEMS', '2');
            $I->amOnPage('https://localhost/search?q=pageboundarymarker');
            $firstPage = $I->grabMultiple('.search-result-title a');
            $I->assertCount(2, $firstPage);

            foreach (['0', '-1', '99', (string)PHP_INT_MAX] as $page) {
                $I->amOnPage('https://localhost/search?q=pageboundarymarker&p=' . $page);
                $I->seeResponseCodeIs(Response::HTTP_OK);
                $I->assertSame($firstPage, $I->grabMultiple('.search-result-title a'));
                $I->assertSame('pageboundarymarker', $I->grabAttributeFrom('#register_search_input_ext', 'value'));
            }

            $I->amOnPage('https://localhost/search?q=pageboundarymarker&p=2');
            $I->assertCount(1, $I->grabMultiple('.search-result-title a'));
            $I->assertSame([], array_intersect($firstPage, $I->grabMultiple('.search-result-title a')));
        } finally {
            $this->removeIndexEntries($I, $ids);
        }
    }

    public function testSpellingSuggestionsKeepTheOriginalSearchAndRequireAnExplicitClick(\IntegrationTester $I): void
    {
        $ids = [];
        try {
            $ids[] = $this->insertAndIndexContent($I, ContentType::POST, 'Поиск', 'search-layout-hint', '<p>Поиск по материалам.</p>');
            $ids[] = $this->insertAndIndexContent($I, ContentType::POST, 'Истории театров', 'search-spelling-hint');

            foreach (['English' => 'Perhaps you meant:', 'Russian' => 'Возможно, вы искали:'] as $language => $label) {
                $I->setConfigValue('REGISTER_LANGUAGE', $language);
                $I->amOnPage('https://localhost/search?q=gjbcr&p=2');
                $I->seeResponseCodeIs(Response::HTTP_OK);
                $I->assertSame('gjbcr', $I->grabAttributeFrom('#register_search_input_ext', 'value'));
                $I->see($label, '.register_search_suggestions');
                $I->assertSame(['поиск'], $I->grabMultiple('.register_search_suggestions a'));
                $I->dontSeeElement('.search-result-title');
                $link = $I->grabAttributeFrom('.register_search_suggestions a', 'href');
                $I->assertSame('/search?q=' . rawurlencode('поиск'), $link);
                $I->amOnPage('https://localhost' . $link);
                $I->assertSame('поиск', $I->grabAttributeFrom('#register_search_input_ext', 'value'));
                $I->seeElement('.search-result-title a[href="/all/search-layout-hint"]');
                $I->dontSeeElement('.register_search_suggestions');
            }

            $I->amOnPage('https://localhost/search?q=' . rawurlencode('истроия театра'));
            $I->assertSame('истроия театра', $I->grabAttributeFrom('#register_search_input_ext', 'value'));
            $I->seeElement('.search-result-title a[href="/all/search-spelling-hint"]');
            $I->assertContains('история театра', $I->grabMultiple('.register_search_suggestions a'));

            $I->amOnPage('https://localhost/search/feed.json?q=gjbcr');
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $payload = $I->grabJson();
            $I->assertIsArray($payload);
            $I->assertSame([], $payload['items']);
        } finally {
            $this->removeIndexEntries($I, $ids);
        }
    }

    public function testQuotedPhrasesFilterSearchPagesAndFeeds(\IntegrationTester $I): void
    {
        $ids = [];
        try {
            $ids[] = $this->insertAndIndexContent(
                $I, ContentType::POST, 'Required phrase', 'search-required-phrase',
                '<p>searchphrasealpha searchphrasebeta</p>',
            );
            $ids[] = $this->insertAndIndexContent(
                $I, ContentType::POST, 'searchphrasealpha separated searchphrasebeta', 'search-gap-phrase',
            );

            $query = rawurlencode('"searchphrasealpha searchphrasebeta"');
            $I->amOnPage('https://localhost/search?q=' . $query);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $I->assertSame(['Required phrase'], $I->grabMultiple('.search-result-title a'));
            $I->assertSame('/all/search-required-phrase', $I->grabAttributeFrom('.search-result-title a', 'href'));

            foreach (['rss', 'feed.json'] as $feed) {
                $I->amOnPage('https://localhost/search/' . $feed . '?q=' . $query);
                $I->seeResponseCodeIs(Response::HTTP_OK);
                $I->assertStringContainsString('/all/search-required-phrase', $I->grabResponse());
                $I->assertStringNotContainsString('/all/search-gap-phrase', $I->grabResponse());
            }
        } finally {
            $this->removeIndexEntries($I, $ids);
        }
    }

    public function testResultCounterHasNoFinalPeriodInEitherLanguage(\IntegrationTester $I): void
    {
        $ids = [];
        try {
            for ($index = 0; $index < 5; ++$index) {
                $body = '<p>searchpresentationmany';
                if ($index < 2) {
                    $body .= ' searchpresentationpair';
                }

                if ($index === 0) {
                    $body .= ' searchpresentationsingle';
                }

                $ids[] = $this->insertAndIndexContent($I, ContentType::POST, 'Counter fixture ' . $index, 'search-counter-' . $index, $body . '</p>');
            }

            foreach ([
                'Russian' => [
                    'searchpresentationsingle' => 'Нашлась 1 страница',
                    'searchpresentationpair' => 'Нашлось 2 страницы',
                    'searchpresentationmany' => 'Нашлось 5 страниц',
                ],
                'English' => [
                    'searchpresentationsingle' => 'Found 1 page',
                    'searchpresentationmany' => 'Found 5 pages',
                ],
            ] as $language => $cases) {
                $I->setConfigValue('REGISTER_LANGUAGE', $language);
                foreach ($cases as $query => $expected) {
                    $I->amOnPage('https://localhost/search?q=' . $query);
                    $I->seeResponseCodeIs(Response::HTTP_OK);
                    $I->assertSame([$expected], $I->grabMultiple('.register_search_found_num'));
                }
            }
        } finally {
            $this->removeIndexEntries($I, $ids);
        }
    }

    public function testFindsShortTagsOnPublishedPostsAndPages(\IntegrationTester $I): void
    {
        /** @var TagRepository $tags */
        $tags = $I->grabService(TagRepository::class);
        $ids = [];
        try {
            $ids[] = $post = $this->insertAndIndexContent($I, ContentType::POST, 'Short post tags', 'search-short-post-tags');
            $tags->replace($post, $tags->findOrCreateIdsByNames(['42', 'Go', 'ИИ', 'C']));
            $ids[] = $page = $this->insertAndIndexContent($I, ContentType::PAGE, 'Short page tags', 'search-short-page-tags');
            $tags->replace($page, $tags->findOrCreateIdsByNames(['7']));
            $ids[] = $otherPost = $this->insertAndIndexContent($I, ContentType::POST, 'Other tags', 'search-other-tags');
            $tags->replace($otherPost, $tags->findOrCreateIdsByNames(['420', 'Google']));

            foreach (['42', 'go', 'ии', 'c', '7'] as $query) {
                $I->amOnPage('https://localhost/search?q=' . rawurlencode($query));
                $I->seeResponseCodeIs(Response::HTTP_OK);
                $name = ['go' => 'Go', 'ии' => 'ИИ', 'c' => 'C'][$query] ?? $query;
                $I->assertSame([$name], $I->grabMultiple('.register_search_found_tags a'));
                $I->assertSame('/tags/' . rawurlencode($name) . '/', $I->grabAttributeFrom('.register_search_found_tags a', 'href'));
            }

            $I->amOnPage('https://localhost/search?q=4');
            $I->dontSeeElement('.register_search_found_tags');
        } finally {
            $this->removeIndexEntries($I, $ids);
        }
    }

    public function testHighlightedTitleQuotesAreTheSameForGuestsAndAuthors(\IntegrationTester $I): void
    {
        $titles = [
            'Примеры «37 шагов» и «вторая тема»',
            '«37 строк»: первая часть',
            '"37 слов": другая часть',
            'Книга «Мир «37 слов»»',
        ];
        $expected = [
            'Примеры «37 шагов» и «вторая тема»',
            '«37 строк»: первая часть',
            '«37 слов»: другая часть',
            'Книга «Мир „37 слов“»',
        ];
        $ids = [];
        try {
            foreach ($titles as $index => $title) {
                $ids[] = $this->insertAndIndexContent(
                    $I,
                    ContentType::POST,
                    $title,
                    'search-quoted-title-' . $index,
                    '<p>quotepresentationmarker «Незавершённая цитата</p>',
                );
            }

            $I->setConfigValue('REGISTER_LANGUAGE', 'Russian');
            $this->assertTitleQuotes($I, $expected);

            $I->setConfigValue('REGISTER_LANGUAGE', 'English');
            $I->login('admin', 'admin');
            $I->setConfigValue('REGISTER_LANGUAGE', 'Russian');
            $this->assertTitleQuotes($I, $expected);

            /** @var DbLayer $dbLayer */
            $dbLayer = $I->grabService(DbLayer::class);
            foreach ($ids as $index => $id) {
                $storedTitle = $dbLayer->select('title')->from(ContentSchema::TABLE_NAME)
                    ->where('id = :id')->setParameter('id', $id->value)->execute()->result();
                $I->assertSame($titles[$index], $storedTitle, 'Search rendering must not rewrite stored titles.');
            }
        } finally {
            $this->removeIndexEntries($I, $ids);
        }
    }

    /** @param list<string> $expected */
    private function assertTitleQuotes(\IntegrationTester $I, array $expected): void
    {
        $url = 'https://localhost/search?q=quotepresentationmarker+37';
        $I->amOnPage($url);
        $I->seeResponseCodeIs(Response::HTTP_OK);
        foreach ($expected as $index => $title) {
            $selector = '.search-result-title a[href="/all/search-quoted-title-' . $index . '"]';
            $I->assertSame([$title], array_map($this->normalizeSpaces(...), $I->grabMultiple($selector)));
            $I->assertSame(['37'], $I->grabMultiple($selector . ' .register_search_highlight'));
        }

        $I->sendRequestWithHeaders($url, ['X-Register-Navigation' => 'partial']);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $payload = $I->grabJson();
        $I->assertIsArray($payload);
        $crawler = new Crawler();
        $crawler->addHtmlContent($payload['fragment'], 'UTF-8');
        foreach ($expected as $index => $title) {
            $node = $crawler->filter('.search-result-title a[href="/all/search-quoted-title-' . $index . '"]');
            $I->assertSame($title, $this->normalizeSpaces($node->text()));
        }
    }

    private function normalizeSpaces(string $text): string
    {
        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $text) ?? $text);
    }

    private function insertAndIndexContent(
        \IntegrationTester $I,
        ContentType $type,
        string $title,
        string $slug,
        string $body = '<p>Fixture text</p>',
    ): ContentId {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':type')->setParameter('type', $type->value)
            ->setValue('slug_scope', "'root'")
            ->setValue('slug', ':slug')->setParameter('slug', ($type === ContentType::POST ? 'all/' : '') . $slug)
            ->setValue('title', ':title')->setParameter('title', $title)
            ->setValue('body', ':body')->setParameter('body', $body)
            ->setValue('excerpt', "''")
            ->setValue('created_at', '1700000001')
            ->setValue('published_at', '1700000001')
            ->setValue('updated_at', '1700000001')
            ->setValue('revision', '1')
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('series', "''")
            ->setValue('author_id', 'NULL')
            ->execute();
        $id = new ContentId($type, (int)$dbLayer->insertId());

        /** @var ContentRepository $contents */
        $contents = $I->grabService(ContentRepository::class);
        $content = $contents->find($id);
        $I->assertInstanceOf(ContentItem::class, $content);
        /** @var SearchDocumentFactory $factory */
        $factory = $I->grabService(SearchDocumentFactory::class);
        /** @var Indexer $indexer */
        $indexer = $I->grabService(Indexer::class);
        $indexer->index($factory->create($content));

        return $id;
    }

    /** @param list<ContentId> $ids */
    private function removeIndexEntries(\IntegrationTester $I, array $ids): void
    {
        /** @var Indexer $indexer */
        $indexer = $I->grabService(Indexer::class);
        foreach ($ids as $id) {
            $indexer->removeById((string)$id, null);
        }
    }
}
