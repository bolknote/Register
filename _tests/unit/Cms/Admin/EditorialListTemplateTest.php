<?php

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use Register\AdminYard\Form\SearchInput;
use Register\AdminYard\Form\Select;

final class EditorialListTemplateTest extends Unit
{
    public function testSearchIsVisibleWhileAdvancedFiltersAndSavedViewsStayCollapsed(): void
    {
        $document = $this->document($this->render('find me'));
        self::assertSame(1, $document->query('//form[contains(@class,"list-filter-form")]//input[@name="search"]')->length);
        self::assertSame(0, $document->query('//details[contains(@class,"filter-panel")]//input[@name="search"]')->length);
        self::assertSame(0, $document->query('//details[contains(@class,"filter-panel") and @open]')->length);
        self::assertSame(0, $document->query('//details[@data-saved-list-views and @open]')->length);
        self::assertSame(0, $document->query('//form//form')->length);
        self::assertSame(1, $document->query('//form[contains(@class,"list-filter-form")]//input[@name="state" and @value="scheduled"]')->length);
    }

    public function testBulkActionsAreHiddenUntilSelectionAndContextSurvivesNavigation(): void
    {
        $document = $this->document($this->render());
        self::assertSame(1, $document->query('//section[@data-bulk-list and @hidden]')->length);
        self::assertSame(1, $document->query('//input[@data-bulk-row-select]')->length);
        self::assertSame(2, $document->query('//input[@data-bulk-select-all]')->length);
        self::assertSame(1, $document->query('//div[contains(@class,"list-mobile-tools")]//input[@data-bulk-select-all]')->length);
        self::assertSame(1, $document->query('//details[contains(@class,"list-mobile-sort")]//a[contains(@href,"sort_field=title")]')->length);
        self::assertGreaterThan(0, $document->query('//a[contains(@class,"pagination-link") and contains(@href,"state=scheduled")]')->length);
        self::assertSame(1, $document->query('//a[contains(@class,"sort-link") and contains(@href,"state=scheduled")]')->length);
        self::assertSame(1, $document->query('//td[@data-label="Title"]')->length);
        self::assertSame(1, $document->query('//a[@href="/?editor=new"]')->length);
    }

    public function testActiveAdvancedFilterOpensWithoutDuplicatingSearch(): void
    {
        $document = $this->document($this->render('', 'writer'));
        self::assertSame(1, $document->query('//details[contains(@class,"filter-panel") and @open]')->length);
        self::assertSame(1, $document->query('//input[@name="search"]')->length);
        $reset = $document->query('//a[contains(@href,"apply_filter=1") and contains(@href,"author_id=")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $reset);
        self::assertStringContainsString('state=scheduled', $reset->getAttribute('href'));
    }

    private function document(string $html): EditorialListDocument
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        return new EditorialListDocument($document);
    }

    private function render(string $search = '', string $author = ''): string
    {
        $trans = static fn(string $key): string => $key;
        $isGranted = static fn(string $permission): bool => false;
        $basePath = '';
        $title = 'Posts';
        $entityName = 'BlogPost';
        $authorControl = new Select('author_id');
        $authorControl->setOptions(['' => 'All', 'writer' => 'Author']);

        $filterControls = ['search' => (new SearchInput('search'))->setValue($search), 'author_id' => $authorControl->setValue($author)];
        $filterLabels = ['search' => 'Search', 'author_id' => 'Author'];
        $filterData = ['search' => $search, 'author_id' => $author, 'state' => 'scheduled'];
        $listContextParams = ['state' => 'scheduled'];
        $listPrimaryActions = [['url' => '/?editor=new', 'label' => 'Write post']];
        $header = ['title' => 'Title'];
        $hint = [];
        $sortableFields = ['title'];
        $sortField = 'title';
        $sortDirection = 'desc';
        $entityActions = [];
        $savedListViews = [];
        $savedListViewState = ['filters' => $filterData, 'sort_field' => $sortField, 'sort_direction' => $sortDirection];
        $savedListViewCsrfToken = 'fixture';
        $activeSavedListViewId = null;
        $bulkListActions = ['publish'];
        $bulkListCsrfToken = 'fixture';
        $rows = [[
            'primary_key' => ['id' => 1],
            'csrf_token' => 'fixture',
            'rendered_actions' => '<span data-bulk-row-permitted hidden></span>',
            'cells' => ['title' => ['type' => 'string', 'content' => 'Example post']],
        ]];
        $page = 1;
        $limit = 20;
        $totalCount = 45;
        ob_start();
        require dirname(__DIR__, 4) . '/_admin/templates/admin-yard/list.php.inc';
        return (string)ob_get_clean();
    }
}

/** Fails a test explicitly on an invalid XPath instead of dereferencing false. */
final class EditorialListDocument extends \DOMXPath
{
    /** @return \DOMNodeList<\DOMNode|\DOMNameSpaceNode> */
    #[\Override]
    public function query(string $expression, ?\DOMNode $contextNode = null, bool $registerNodeNS = true): \DOMNodeList
    {
        $nodes = parent::query($expression, $contextNode, $registerNodeNS);
        if ($nodes === false) {
            throw new \LogicException('Invalid test XPath: ' . $expression);
        }

        return $nodes;
    }
}
