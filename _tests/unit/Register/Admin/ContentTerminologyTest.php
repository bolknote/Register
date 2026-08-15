<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Admin;

use Codeception\Test\Unit;
use Register\Module\Blog\Admin\TranslationProvider;

final class ContentTerminologyTest extends Unit
{
    public function testAdminUsesPostPageAndContentForDistinctConcepts(): void
    {
        $russian = $this->adminTranslations('ru');
        self::assertSame('Материалы', $russian['Materials']);
        self::assertSame('Пост', $russian['Post']);
        self::assertSame('Страница', $russian['Page']);
        self::assertSame('Страницы', $russian['Pages']);
        self::assertSame('Материал', $russian['Content']);
        self::assertSame('Комментарии к страницам', $russian['Page comments']);
        self::assertSame('На страницах', $russian['Used in pages']);

        $english = $this->adminTranslations('en');
        self::assertSame('Content', $english['Materials']);
        self::assertSame('Post', $english['Post']);
        self::assertSame('Page', $english['Page']);
        self::assertSame('Pages', $english['Pages']);
        self::assertSame('Content', $english['Content']);
        self::assertSame('Page comments', $english['Page comments']);
        self::assertSame('In pages', $english['Used in pages']);

        foreach (['Article', 'Articles', 'Article comments', 'Used in articles', 'Used in articles info'] as $legacyKey) {
            self::assertArrayNotHasKey($legacyKey, $russian);
            self::assertArrayNotHasKey($legacyKey, $english);
        }
    }

    public function testBlogCallsPostsPostsInEveryRussianInterface(): void
    {
        $admin = (new TranslationProvider())->getTranslations('Russian', 'ru');
        $public = require $this->projectRoot() . '/_include/src/Register/Module/Blog/resources/lang/Russian.php';

        self::assertSame('Посты', $admin['Posts']);
        self::assertSame('Комментарии к постам', $admin['Blog comments']);
        self::assertSame('В постах', $admin['Used in posts']);
        self::assertSame('Пост', $public['Post']);
        self::assertSame('Посты', $public['Navigation']);
        self::assertSame('Посты по этой теме:', $public['Posts by tag']);
        self::assertSame('Последние посты в блоге', $public['RSS blog link title']);

        $legacyBlogTerms = '/\b(?:запись|записи|записей|записям|записями|записях|заметка|заметки|заметок|статья|статьи|статей)\b/ui';
        self::assertDoesNotMatchRegularExpression($legacyBlogTerms, $this->stringValues($admin));
        self::assertDoesNotMatchRegularExpression($legacyBlogTerms, $this->stringValues($public));
    }

    public function testSharedCommentCopyUsesContentInsteadOfAssumingAnArticle(): void
    {
        $russian = require $this->projectRoot() . '/_lang/Russian/comments.php';
        $english = require $this->projectRoot() . '/_lang/English/comments.php';

        self::assertStringContainsString('к материалу', $russian['Email pattern']);
        self::assertStringContainsString('прокомментированному материалу', $russian['Comment sent info']);
        self::assertStringNotContainsString('стать', mb_strtolower($this->stringValues($russian)));

        self::assertStringContainsString('comments on the content', $english['Email pattern']);
        self::assertStringContainsString('return to the content', $english['Comment sent info']);
        self::assertDoesNotMatchRegularExpression('/\barticles?\b/i', $this->stringValues($english));
    }

    public function testSharedCommentListLabelsPermanentContentAsAPage(): void
    {
        $template = file_get_contents($this->projectRoot() . '/_admin/templates/comment/view-content.php.inc');
        self::assertIsString($template);
        self::assertStringContainsString("ContentType::PAGE => \$trans('Page')", $template);
        self::assertStringNotContainsString("\$trans('Article')", $template);
    }

    /** @return array<mixed> */
    private function adminTranslations(string $locale): array
    {
        return require $this->projectRoot() . '/_admin/lang/' . $locale . '/admin.php';
    }

    /** @param array<mixed> $translations */
    private function stringValues(array $translations): string
    {
        return implode("\n", array_filter($translations, is_string(...)));
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
