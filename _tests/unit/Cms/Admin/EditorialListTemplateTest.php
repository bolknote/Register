<?php

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use Register\AdminYard\Form\SearchInput;
use Register\AdminYard\Form\Select;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;

final class EditorialListTemplateTest extends Unit
{
    public function testSearchIsVisibleWhileAdvancedFiltersAndSavedViewsStayCollapsed(): void
    {
        $document = $this->document($this->render('find me'));
        self::assertCount(1, $document->query('//form[contains(@class,"list-filter-form")]//input[@name="search"]'));
        self::assertCount(0, $document->query('//details[contains(@class,"filter-panel")]//input[@name="search"]'));
        self::assertCount(0, $document->query('//details[contains(@class,"filter-panel") and @open]'));
        self::assertCount(0, $document->query('//details[@data-saved-list-views and @open]'));
        self::assertCount(0, $document->query('//form//form'));
        self::assertCount(1, $document->query('//form[contains(@class,"list-filter-form")]//input[@name="state" and @value="scheduled"]'));
    }

    public function testBulkActionsAreHiddenUntilSelectionAndContextSurvivesNavigation(): void
    {
        $document = $this->document($this->render());
        self::assertCount(1, $document->query('//section[@data-bulk-list and @hidden]'));
        self::assertCount(1, $document->query('//input[@data-bulk-row-select]'));
        self::assertCount(2, $document->query('//input[@data-bulk-select-all]'));
        self::assertCount(1, $document->query('//div[contains(@class,"list-mobile-tools")]//input[@data-bulk-select-all]'));
        self::assertCount(1, $document->query('//details[contains(@class,"list-mobile-sort")]//a[contains(@href,"sort_field=title")]'));
        self::assertNotEmpty($document->query('//a[contains(@class,"pagination-link") and contains(@href,"state=scheduled")]'));
        self::assertCount(1, $document->query('//a[contains(@class,"sort-link") and contains(@href,"state=scheduled")]'));
        self::assertCount(1, $document->query('//td[@data-label="Title"]'));
        self::assertCount(1, $document->query('//a[@href="/?editor=new"]'));
    }

    public function testActiveAdvancedFilterOpensWithoutDuplicatingSearch(): void
    {
        $document = $this->document($this->render('', 'writer'));
        self::assertCount(1, $document->query('//details[contains(@class,"filter-panel") and @open]'));
        self::assertCount(1, $document->query('//input[@name="search"]'));
        $reset = $document->query('//a[contains(@href,"apply_filter=1") and contains(@href,"author_id=")]')[0] ?? null;
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
        $authorControl = new Select('author_id');
        $authorControl->setOptions(['' => 'All', 'writer' => 'Author']);

        $filterData = ['search' => $search, 'author_id' => $author, 'state' => 'scheduled'];

        return (new TemplateRenderer(new Translator([], 'en')))->render(
            dirname(__DIR__, 4) . '/_admin/templates/admin-yard/list.php.inc',
            [
                'isGranted' => static fn(): bool => false,
                'basePath' => '',
                'title' => 'Posts',
                'entityName' => 'BlogPost',
                'filterControls' => ['search' => (new SearchInput('search'))->setValue($search), 'author_id' => $authorControl->setValue($author)],
                'filterLabels' => ['search' => 'Search', 'author_id' => 'Author'],
                'filterData' => $filterData,
                'listContextParams' => ['state' => 'scheduled'],
                'listPrimaryActions' => [['url' => '/?editor=new', 'label' => 'Write post']],
                'header' => ['title' => 'Title'],
                'hint' => [],
                'sortableFields' => ['title'],
                'sortField' => 'title',
                'sortDirection' => 'desc',
                'entityActions' => [],
                'savedListViews' => [],
                'savedListViewState' => ['filters' => $filterData, 'sort_field' => 'title', 'sort_direction' => 'desc'],
                'savedListViewCsrfToken' => 'fixture',
                'activeSavedListViewId' => null,
                'bulkListActions' => ['publish'],
                'bulkListCsrfToken' => 'fixture',
                'rows' => [[
                    'primary_key' => ['id' => 1],
                    'csrf_token' => 'fixture',
                    'rendered_actions' => '<span data-bulk-row-permitted hidden></span>',
                    'cells' => ['title' => ['type' => 'string', 'content' => 'Example post']],
                ]],
                'page' => 1,
                'limit' => 20,
                'totalCount' => 45,
            ],
        );
    }
}

/** Fails a test explicitly on an invalid XPath instead of dereferencing false. */
final readonly class EditorialListDocument
{
    private \DOMXPath $xpath;

    public function __construct(\DOMDocument $document)
    {
        $this->xpath = new \DOMXPath($document);
    }

    /** @return list<\DOMNode|\DOMNameSpaceNode> */
    public function query(string $expression): array
    {
        $nodes = $this->xpath->query($expression);
        if ($nodes === false) {
            throw new \LogicException('Invalid test XPath: ' . $expression);
        }

        return array_values(iterator_to_array($nodes));
    }
}
