<?php
/**
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Template;

use S2\Cms\Comment\Antispam\CommentFormTokenManager;
use S2\Cms\Config\BoolProxy;
use S2\Cms\Config\IntProxy;
use S2\Cms\Config\StringProxy;
use S2\Cms\Helper\StringHelper;
use S2\Cms\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use S2\Cms\Pdo\DbLayerException;

class HtmlTemplate
{
    /**
     * @var array<mixed>
     */
    protected array $page = [];

    /** @var list<array{title: string, link: string|null}> */
    protected array $breadCrumbs = [];

    /** @var array<string, string> */
    private array $navLinks = [];

    /** @var array<string, string> */
    private array $replace = [];

    private bool $notFound = false;

    public function __construct(
        private readonly string                   $template,
        private readonly RequestStack             $requestStack,
        private readonly UrlBuilder               $urlBuilder,
        private readonly TranslatorInterface      $translator,
        private readonly Viewer                   $viewer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly StringProxy              $siteName,
        private readonly BoolProxy                $enabledComments,
        private readonly StringProxy              $webmaster,
        private readonly StringProxy              $webmasterEmail,
        private readonly IntProxy                 $startYear,
        private readonly bool                     $debugView,
        private readonly ?string                  $canonicalUrlPrefix,
        private readonly CommentFormTokenManager  $commentFormTokenManager,
    ) {
    }

    /**
     * @param array<mixed>|bool|float|int|string|StringProxy|null $content
     */
    public function putInPlaceholder(string $placeholder, array|bool|float|int|string|StringProxy|null $content): static
    {
        $this->page[$placeholder] = $content;

        return $this;
    }

    public function getFromPlaceholder(string $placeholder): mixed
    {
        return $this->page[$placeholder] ?? null;
    }

    public function addBreadCrumb(string $title, ?string $link = null): static
    {
        $this->breadCrumbs[] = ['title' => $title, 'link' => $link];

        return $this;
    }

    public function toHttpResponse(): Response
    {
        $template = $this->template;

        $replace = [];

        // HTML head
        $replace['<!-- s2_head_title -->'] = $this->buildHeadTitle();

        // Meta tags processing
        $meta_tags = [
            '<meta name="Generator" content="Register">',
        ];

        if ($this->hasContent('meta_keywords')) {
            $meta_tags[] = '<meta name="keywords" content="' . s2_htmlencode($this->page['meta_keywords']) . '" />';
        }

        if ($this->hasContent('meta_description')) {
            $meta_tags[] = '<meta name="description" content="' . s2_htmlencode($this->page['meta_description']) . '" />';
        }

        if ($this->hasContent('canonical_path') && $this->canonicalUrlPrefix !== null) {
            $meta_tags[] = '<link rel="canonical" href="' . $this->canonicalUrlPrefix . s2_htmlencode($this->page['canonical_path']) . '" />';
        }

        $replace['<!-- s2_meta -->'] = implode("\n", $meta_tags);

        if (!$this->hasContent('rss_link')) {
            $this->page['rss_link'] = ['<link rel="alternate" type="application/rss+xml" title="' . $this->translator->trans('RSS link title') . '" href="' . $this->urlBuilder->link('/rss.xml') . '" />'];
        }

        $replace['<!-- s2_rss_link -->'] = implode("\n", $this->stringListValue($this->page['rss_link']));

        // Content
        $replace['<!-- s2_site_title -->'] = $this->buildSiteTitle();

        $link_navigation = [];
        foreach ($this->navLinks as $link_rel => $link_href) {
            $link_navigation[] = '<link rel="' . $link_rel . '" href="' . $link_href . '" />';
        }

        $replace['<!-- s2_navigation_link -->'] = implode("\n", $link_navigation);

        $replace['<!-- s2_author -->']      = $this->hasContent('author') ? '<div class="author">' . $this->renderValue($this->page['author']) . '</div>' : '';
        $replace['<!-- s2_title -->']       = $this->hasContent('title') ? $this->viewer->render(
            'title',
            array_intersect_key($this->page, ['title' => 1, 'favorite' => 1, 'favorite_link' => 1])
        ) : '';
        $replace['<!-- s2_date -->']        = $this->hasContent('date') ? '<div class="date">' . $this->viewer->date($this->intValue($this->page['date'])) . '</div>' : '';
        $replace['<!-- s2_crumbs -->']      = \count($this->breadCrumbs) > 0 ? $this->viewer->render('breadcrumbs', ['breadcrumbs' => $this->breadCrumbs]) : '';
        $replace['<!-- s2_subarticles -->'] = $this->page['subcontent'] ?? '';

        foreach ($this->simplePlaceholders() as $placeholderName) {
            $replace['<!-- s2_' . $placeholderName . ' -->'] = $this->renderValue($this->page[$placeholderName] ?? '');
        }

        $antispamVisitorCookie = null;
        if ($this->hasContent('commented') && $this->enabledComments->get()) {
            $antispamRequest = $this->requestStack->getCurrentRequest();
            if (!$antispamRequest instanceof Request) {
                throw new \LogicException('A request is required to render the comment form.');
            }

            $comment_array = [
                'id' => $this->page['id'],
            ];

            if ($this->hasContent('comment_form') && \is_array($this->page['comment_form'])) {
                $comment_array += $this->page['comment_form'];
            }

            if (!array_key_exists('parent_id', $comment_array)) {
                $replyId = $antispamRequest->query->getInt('reply_to');
                $comment_array += [
                    'parent_id'    => $replyId > 0 ? $replyId : null,
                    'reply_number' => max(0, $antispamRequest->query->getInt('reply_number')),
                    'reply_name'   => mb_substr(trim($antispamRequest->query->getString('reply_name')), 0, 50),
                ];
            }

            $event = new TemplatePreCommentRenderEvent([$this->translator->trans('Comment syntax info')]);
            $this->eventDispatcher->dispatch($event);

            $antispamVisitorToken = $this->commentFormTokenManager->getOrCreateVisitorToken($antispamRequest);
            $antispamVisitorCookie = $this->commentFormTokenManager->createVisitorCookie($antispamVisitorToken, $antispamRequest);
            $replace['<!-- s2_comment_form -->'] = $this->viewer->render('comment_form', [
                ...$comment_array,
                'syntaxHelpItems' => $event->syntaxHelpItems,
                'action'          => $this->urlBuilder->link($antispamRequest->getPathInfo()),
                'cancelReplyUrl'  => $this->urlBuilder->link($antispamRequest->getPathInfo()) . '#add-comment',
                'antispamToken'   => $this->commentFormTokenManager->issue(
                    $antispamRequest->getPathInfo(),
                    $antispamVisitorToken,
                ),
            ]);
        } else {
            $replace['<!-- s2_comment_form -->'] = '';
        }

        $replace['<!-- s2_back_forward -->'] = $this->hasContent('back_forward') ? $this->viewer->render('back_forward', ['links' => $this->page['back_forward']]) : '';

        // Footer
        $replace['<!-- s2_copyright -->'] = $this->buildFooter();

        $this->eventDispatcher->dispatch(new TemplateEvent($this), TemplateEvent::EVENT_PRE_REPLACE);

        $replace = array_merge($replace, $this->replace);

        $etag = md5($template);

        // Replacing placeholders and calculating hash for ETag header
        foreach ($replace as $what => $to) {
            if ($this->debugView && $to !== '' && !in_array($what, ['<!-- s2_head_title -->', '<!-- s2_navigation_link -->', '<!-- s2_rss_link -->', '<!-- s2_meta -->', '<!-- s2_styles -->'], true)) {

                $title = '<pre style="color: red; font-size: 12px; opacity: 0.6; margin: 0 -100% 0 0; width: 100%; text-align: center; line-height: 1; position: relative; float: left; z-index: 1000; pointer-events: none;">' . s2_htmlencode($what) . '</pre>';
                $to    = '<div style="border: 1px solid rgba(255, 0, 0, 0.4); margin: 1px;">' .
                    $title . $to .
                    '</div>';
            }

            $etag .= md5($to);

            $template = str_replace($what, $to, $template);
        }

        $finalReplaceEvent = new TemplateFinalReplaceEvent($template);
        $this->eventDispatcher->dispatch($finalReplaceEvent);
        $etag .= $finalReplaceEvent->getHash();

        $response = new Response($template);
        if ($antispamVisitorCookie instanceof Cookie) {
            $response->headers->setCookie($antispamVisitorCookie);
        }

        $response->setEtag(md5($etag));
        if ($this->notFound) {
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
        }

        return $response;
    }

    public function hasPlaceholder(string $placeholder): bool
    {
        return str_contains($this->template, $placeholder);
    }

    public function setLink(string $rel, string $link): static
    {
        $this->navLinks[$rel] = $link;

        return $this;
    }

    /**
     * Register a new placeholder that is not known to this class.
     * Supposed to be used in extensions for their own custom placeholders.
     */
    public function registerPlaceholder(string $placeholder, string $value): static
    {
        $this->replace[$placeholder] = $value;

        return $this;
    }

    public function isNotFound(): bool
    {
        return $this->notFound;
    }

    public function markAsNotFound(): static
    {
        $this->notFound = true;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    protected function simplePlaceholders(): array
    {
        return [
            'section_link',
            'excerpt',
            'text',
            'tags',
            'recommendations',
            'comments',
            'menu_siblings',
            'menu_children',
            'menu_subsections',
            'article_tags'
        ];
    }

    /**
     * @throws DbLayerException
     */
    private function buildSiteTitle(): string
    {
        $siteName    = s2_htmlencode($this->siteName->get());
        $requestPath = $this->requestStack->getCurrentRequest()?->getPathInfo();

        if ($requestPath === '/') {
            return $siteName;
        }

        return '<a href="' . $this->urlBuilder->link('/') . '">' . $siteName . '</a>';
    }

    private function buildHeadTitle(): string
    {
        if ($this->hasContent('head_title')) {
            return $this->renderValue($this->page['head_title']);
        }

        $siteName = $this->siteName->get();
        if (!$this->hasContent('title')) {
            return s2_htmlencode($siteName);
        }

        $pageTitle = $this->renderValue($this->page['title']);
        if ($pageTitle === $siteName) {
            return s2_htmlencode($siteName);
        }

        return s2_htmlencode($pageTitle) . ' &mdash; ' . s2_htmlencode($siteName);
    }

    /**
     * @throws DbLayerException
     */
    private function buildFooter(): string
    {
        $requestUri = $this->requestStack->getCurrentRequest()?->getPathInfo();

        $webmaster = $this->webmaster->get();
        $email     = $this->webmasterEmail->get();
        $startYear = $this->startYear->get();

        $author = $webmaster !== '' ? $webmaster : $this->siteName->get();
        if ($webmaster !== '' && $email !== '') {
            $copyrightOwner = StringHelper::jsMailTo($author, $email);
        } else {
            $escapedAuthor  = s2_htmlencode($author);
            $copyrightOwner = $requestUri !== '/'
                ? '<a href="' . $this->urlBuilder->link('/') . '">' . $escapedAuthor . '</a>'
                : $escapedAuthor;
        }

        $currentYear = (int)date('Y');
        $copyright   = $startYear !== $currentYear
            ? \sprintf($this->translator->trans('Copyright 2'), $copyrightOwner, $startYear, $currentYear)
            : \sprintf($this->translator->trans('Copyright 1'), $copyrightOwner, $currentYear);

        $engineCredit = \sprintf(
            $this->translator->trans('Engine credit'),
            '<a href="https://github.com/bolknote/Register">Register</a>'
        );
        $loginLabel = s2_htmlencode($this->translator->trans('Administration login'));

        return '<span class="copyright-text">' . $copyright . '</span>' .
            '<a class="footer-rss" href="' . $this->urlBuilder->link('/rss.xml') . '">RSS</a>' .
            '<span class="engine-credit">' . $engineCredit . '</span>' .
            '<a class="visual-login" href="' . $this->urlBuilder->link('/_admin/index.php') .
            '" aria-label="' . $loginLabel . '" title="' . $loginLabel . '">' .
            '<span aria-hidden="true">ℜ</span></a>';
    }

    private function hasContent(string $placeholder): bool
    {
        if (!isset($this->page[$placeholder])) {
            return false;
        }

        $value = $this->page[$placeholder];
        return !in_array($value, ['', '0', 0, 0.0, false, []], true);
    }

    private function renderValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (\is_scalar($value) || $value instanceof \Stringable) {
            return (string)$value;
        }

        throw new \UnexpectedValueException('Template placeholder must contain a renderable value.');
    }

    private function intValue(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \UnexpectedValueException('Template date placeholder must contain an integer timestamp.');
        }

        return $value;
    }

    /** @return list<string> */
    private function stringListValue(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Template list placeholder must contain a list.');
        }

        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw new \UnexpectedValueException('Template list placeholder items must be strings.');
            }
        }

        return $value;
    }
}
