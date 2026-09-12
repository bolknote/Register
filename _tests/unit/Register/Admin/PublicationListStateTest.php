<?php

declare(strict_types = 1);

namespace unit\Register\Admin;

use Codeception\Test\Unit;
use Register\Admin\PublicationListState;

final class PublicationListStateTest extends Unit
{
    public function testStatesAndPublicationDatesCoverDraftsFutureAndOverduePages(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE content (published INTEGER, published_at INTEGER, scheduled_at INTEGER)');

        $insert = $pdo->prepare('INSERT INTO content VALUES (?, ?, ?)');
        self::assertInstanceOf(\PDOStatement::class, $insert);
        foreach ([[0, 500, 0], [1, 500, 0], [0, 500, 1500], [0, 500, 900], [1, 1500, 0], [1, null, 0], [1, null, 1500]] as $row) {
            $insert->execute($row);
        }

        $query = $pdo->query('SELECT ' . PublicationListState::sql(1000) . ' AS state, '
            . PublicationListState::dateSql(1000) . ' AS date FROM content');
        self::assertInstanceOf(\PDOStatement::class, $query);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);

        self::assertSame([
            ['state' => 'draft', 'date' => null],
            ['state' => 'published', 'date' => 500],
            ['state' => 'scheduled', 'date' => 1500],
            ['state' => 'overdue', 'date' => 900],
            ['state' => 'published', 'date' => 1500],
            ['state' => 'published', 'date' => null],
            ['state' => 'published', 'date' => null],
        ], $rows);
    }

    public function testPageTitleKeepsItsEditLinkAndEscapesMetadataWithoutStrikingDrafts(): void
    {
        $row = ['virtual_author_id' => '<b>Author</b>', 'virtual_tags' => 'one & two'];
        $label = '<Page>';
        $value = $label;
        $linkParams = ['entity' => 'Article', 'action' => 'edit', 'id' => 12];
        ob_start();
        require dirname(__DIR__, 4) . '/_admin/templates/article/view-title.php';
        $output = (string)ob_get_clean();

        self::assertStringContainsString('href="?entity=Article&amp;action=edit&amp;id=12"', $output);
        self::assertStringContainsString('&lt;Page&gt;', $output);
        self::assertStringContainsString('&lt;b&gt;Author&lt;/b&gt;', $output);
        self::assertStringContainsString('one &amp; two', $output);
        self::assertStringNotContainsString('<s>', $output);
    }

    public function testNullPublicationDateDoesNotPretendADraftWasPublished(): void
    {
        $value = null;
        ob_start();
        require dirname(__DIR__, 4) . '/_admin/templates/content/publication-date.php.inc';
        $output = (string)ob_get_clean();

        self::assertStringContainsString('—', $output);
        self::assertStringNotContainsString('<time', $output);
    }

    public function testPageListConfigurationKeepsEditorFieldsAndLimitsAuthorChoices(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/_include/src/Register/Admin/AdminConfigProvider.php');
        $pageStart = strpos($source, "        \$articleEntity\n");
        self::assertNotFalse($pageStart);
        $pageEnd = strpos($source, "        \$adminConfig\n", $pageStart);
        self::assertNotFalse($pageEnd);
        $pages = substr($source, $pageStart, $pageEnd - $pageStart);

        self::assertStringContainsString("->setListTemplate('_admin/templates/page-list.php.inc')", $pages);
        self::assertStringContainsString("name: 'section'", $pages);
        self::assertStringContainsString('PublicationListState::sql(time())', $pages);
        self::assertStringContainsString('create_articles = 1 OR edit_site = 1 OR edit_users = 1 OR id IN (', $pages);
        self::assertStringContainsString("WHERE content_type = 'page' AND author_id IS NOT NULL", $pages);
        self::assertStringContainsString("name: 'template',\n                label: \$this->translator->trans('Template'),\n                control: 'input',\n                useOnActions: [FieldConfig::ACTION_EDIT]", $pages);
    }

    public function testTagDateAndSlugRemainEditableButDoNotOccupyListColumns(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/_include/src/Register/Admin/AdminConfigProvider.php');
        $tagStart = strpos($source, "new EntityConfig('Tag'");
        self::assertNotFalse($tagStart);
        $tagEnd = strpos($source, "new EntityConfig('Config'", $tagStart);
        self::assertNotFalse($tagEnd);
        $tags = substr($source, $tagStart, $tagEnd - $tagStart);
        foreach (['modify_time', 'url'] as $field) {
            self::assertMatchesRegularExpression(
                "/name: '" . $field . "',(?:(?!name:).)*useOnActions: \[FieldConfig::ACTION_EDIT, FieldConfig::ACTION_NEW, FieldConfig::ACTION_SHOW\]/s",
                $tags,
            );
        }
    }
}
