<?php
/**
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Template;

use Register\Core\Comment\Antispam\CommentFormTokenManager;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\IntProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Helper\StringHelper;
use Register\Core\Model\AuthenticatedPublicUser;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Register\Core\Pdo\DbLayerException;

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

    /** @var list<string> */
    private array $extraMetaTags = [];

    private bool $notFound = false;

    public function __construct(
        private readonly string                   $template,
        private readonly RequestStack             $requestStack,
        private readonly UrlBuilder               $urlBuilder,
        private readonly TranslatorInterface      $translator,
        private readonly Viewer                   $viewer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly StringProxy              $siteName,
        private readonly StringProxy              $siteTagline,
        private readonly StringProxy              $socialImageDefault,
        private readonly BoolProxy                $enabledComments,
        private readonly StringProxy              $webmaster,
        private readonly StringProxy              $webmasterEmail,
        private readonly IntProxy                 $startYear,
        private readonly bool                     $debugView,
        private readonly ?string                  $canonicalUrlPrefix,
        private readonly CommentFormTokenManager  $commentFormTokenManager,
        private readonly AuthProvider             $authProvider,
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

    /** Adds a product-owned tag to the document head before the template is finalized. */
    public function addMetaTag(string $tag): static
    {
        $this->extraMetaTags[] = $tag;

        return $this;
    }

    public function toHttpResponse(): Response
    {
        $template = $this->template;

        $replace = [];

        // HTML head
        $replace['<!-- register_html_lang -->']  = register_htmlencode($this->translator->trans('locale'));
        $replace['<!-- register_head_title -->'] = $this->buildHeadTitle();

        // Meta tags processing
        $meta_tags = [
            '<meta name="Generator" content="Register">',
        ];

        if ($this->hasContent('meta_keywords')) {
            $meta_tags[] = '<meta name="keywords" content="' . register_htmlencode($this->page['meta_keywords']) . '" />';
        }

        if ($this->hasContent('meta_description')) {
            $meta_tags[] = '<meta name="description" content="' . register_htmlencode($this->page['meta_description']) . '" />';
        }

        if ($this->hasContent('canonical_path') && $this->canonicalUrlPrefix !== null) {
            $meta_tags[] = '<link rel="canonical" href="' . $this->canonicalUrlPrefix . register_htmlencode($this->page['canonical_path']) . '" />';
        }

        array_push($meta_tags, ...$this->buildSocialMetaTags());

        $replace['<!-- register_meta -->'] = implode("\n", $meta_tags);

        if (!$this->hasContent('rss_link')) {
            $this->page['rss_link'] = [
                '<link rel="alternate" type="application/rss+xml" title="' . $this->translator->trans('RSS link title') . '" href="' . $this->urlBuilder->link('/rss') . '" />',
                '<link rel="alternate" type="application/feed+json" title="' . $this->translator->trans('JSON Feed link title') . '" href="' . $this->urlBuilder->link('/feed.json') . '" />',
            ];
        }

        $replace['<!-- register_rss_link -->'] = implode("\n", $this->stringListValue($this->page['rss_link']));

        // Content
        $replace['<!-- register_skip_link_label -->']  = register_htmlencode($this->translator->trans('Skip to content'));
        $replace['<!-- register_breadcrumbs_label -->'] = register_htmlencode($this->translator->trans('Breadcrumbs'));
        $siteTitle = $this->buildSiteTitle();
        $replace['<!-- register_site_title -->'] = $siteTitle;
        $replace['<!-- register_site_header -->'] = $this->buildSiteHeader($siteTitle);

        $link_navigation = [];
        foreach ($this->navLinks as $link_rel => $link_href) {
            $link_navigation[] = '<link rel="' . $link_rel . '" href="' . $link_href . '" />';
        }

        $replace['<!-- register_navigation_link -->'] = implode("\n", $link_navigation);

        $replace['<!-- register_author -->']      = $this->hasContent('author') ? '<div class="author">' . $this->renderValue($this->page['author']) . '</div>' : '';
        $replace['<!-- register_title -->']       = $this->hasContent('title') ? $this->viewer->render(
            'title',
            array_intersect_key($this->page, ['title' => 1, 'favorite' => 1, 'favorite_link' => 1])
        ) : '';
        $replace['<!-- register_date -->']        = $this->hasContent('date') ? '<div class="date">' . $this->viewer->date($this->intValue($this->page['date'])) . '</div>' : '';
        $replace['<!-- register_crumbs -->']      = \count($this->breadCrumbs) > 0 ? $this->viewer->render('breadcrumbs', ['breadcrumbs' => $this->breadCrumbs]) : '';
        $replace['<!-- register_subarticles -->'] = $this->page['subcontent'] ?? '';

        foreach ($this->simplePlaceholders() as $placeholderName) {
            $replace['<!-- register_' . $placeholderName . ' -->'] = $this->renderValue($this->page[$placeholderName] ?? '');
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
            $authenticatedUser = $this->authProvider->getAuthenticatedPublicUser($antispamRequest);
            $antispamToken = $this->commentFormTokenManager->issue(
                $antispamRequest->getPathInfo(),
                $antispamVisitorToken,
            );
            $replace['<!-- register_comment_form -->'] = $this->viewer->render('comment_form', [
                ...$comment_array,
                'authenticatedUser' => $authenticatedUser instanceof AuthenticatedPublicUser
                    ? $authenticatedUser
                    : null,
                'syntaxHelpItems' => $event->syntaxHelpItems,
                'action'          => $this->urlBuilder->link($antispamRequest->getPathInfo()),
                'cancelReplyUrl'  => $this->urlBuilder->link($antispamRequest->getPathInfo()) . '#add-comment',
                'antispamToken'   => $antispamToken,
                'commentFieldNames' => $this->commentFormTokenManager->fieldNames($antispamToken),
            ]);
        } else {
            $replace['<!-- register_comment_form -->'] = '';
        }

        $replace['<!-- register_back_forward -->'] = $this->hasContent('back_forward') ? $this->viewer->render('back_forward', ['links' => $this->page['back_forward']]) : '';

        // Footer
        $replace['<!-- register_copyright -->'] = $this->buildFooter();

        $this->eventDispatcher->dispatch(new TemplateEvent($this), TemplateEvent::EVENT_PRE_REPLACE);

        if ($this->extraMetaTags !== []) {
            $replace['<!-- register_meta -->'] = implode("\n", [...$meta_tags, ...$this->extraMetaTags]);
        }

        $replace = array_merge($replace, $this->replace);

        $etag = md5($template);

        // Replacing placeholders and calculating hash for ETag header
        foreach ($replace as $what => $to) {
            if ($this->debugView && $to !== '' && !in_array($what, [
                '<!-- register_html_lang -->',
                '<!-- register_head_title -->',
                '<!-- register_skip_link_label -->',
                '<!-- register_breadcrumbs_label -->',
                '<!-- register_navigation_link -->',
                '<!-- register_rss_link -->',
                '<!-- register_meta -->',
                '<!-- register_styles -->',
            ], true)) {

                $title = '<pre class="template-debug-label">' . register_htmlencode($what) . '</pre>';
                $to    = '<div class="template-debug-block">' .
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

        $request = $this->requestStack->getCurrentRequest();

        return $request instanceof Request
            ? PartialPageResponse::create($request, $response)
            : $response;
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
        $siteName    = register_htmlencode($this->siteName->get());
        $requestPath = $this->requestStack->getCurrentRequest()?->getPathInfo();

        if ($requestPath === '/') {
            return $siteName;
        }

        return '<a href="' . $this->urlBuilder->link('/') . '">' . $siteName . '</a>';
    }

    private function buildSiteHeader(string $siteTitle): string
    {
        $isHome = $this->requestStack->getCurrentRequest()?->getPathInfo() === '/';
        $tag = $isHome ? 'h1' : 'div';

        return '<' . $tag . ' class="site-title">' . $siteTitle . '</' . $tag . '>';
    }

    private function buildHeadTitle(): string
    {
        if ($this->hasContent('head_title')) {
            return $this->renderValue($this->page['head_title']);
        }

        $siteName = $this->siteName->get();
        if (!$this->hasContent('title')) {
            return register_htmlencode($siteName);
        }

        $pageTitle = $this->renderValue($this->page['title']);
        if ($pageTitle === $siteName) {
            return register_htmlencode($siteName);
        }

        return register_htmlencode($pageTitle) . ' &mdash; ' . register_htmlencode($siteName);
    }

    /** @return list<string> */
    private function buildSocialMetaTags(): array
    {
        $title = $this->plainText($this->hasContent('social_title')
            ? $this->renderValue($this->page['social_title'])
            : $this->buildHeadTitle());
        $description = $this->plainText($this->hasContent('social_description')
            ? $this->renderValue($this->page['social_description'])
            : ($this->hasContent('meta_description')
                ? $this->renderValue($this->page['meta_description'])
                : $this->siteTagline->get()));
        $description = mb_substr($description, 0, 300);

        $request = $this->requestStack->getCurrentRequest();
        $path = $request?->getPathInfo() ?? '/';
        if ($this->hasContent('canonical_path')) {
            $canonicalPath = (string)$this->page['canonical_path'];
            $url = $this->canonicalUrlPrefix !== null
                ? $this->canonicalUrlPrefix . $canonicalPath
                : $this->urlBuilder->rawAbsLink($canonicalPath);
        } else {
            $url = $this->urlBuilder->rawAbsLink(
                $path,
                $request !== null && $request->getQueryString() !== null
                    ? [$request->getQueryString()]
                    : [],
            );
        }

        $configuredImage = $this->hasContent('social_image')
            ? $this->renderValue($this->page['social_image'])
            : '';
        $bodyImage = $configuredImage === '' && $this->hasContent('text')
            ? $this->firstImage($this->renderValue($this->page['text']))
            : '';
        $image = $this->absoluteImage($configuredImage !== ''
            ? $configuredImage
            : ($bodyImage !== '' ? $bodyImage : $this->socialImageDefault->get()));

        $type = $this->hasContent('social_type') && $this->page['social_type'] === 'article'
            ? 'article'
            : 'website';
        $language = strtolower(str_replace('_', '-', $this->translator->trans('locale')));
        $locale = match ($language) {
            'en' => 'en_US',
            'ru' => 'ru_RU',
            default => str_replace('-', '_', $language),
        };
        $siteName = $this->siteName->get();
        $card = $image === '' ? 'summary' : 'summary_large_image';

        $tags = [
            $this->propertyMeta('og:title', $title),
            $this->propertyMeta('og:type', $type),
            $this->propertyMeta('og:url', $url),
            $this->propertyMeta('og:site_name', $siteName),
            $this->propertyMeta('og:locale', $locale),
            $this->namedMeta('twitter:card', $card),
            $this->namedMeta('twitter:title', $title),
        ];

        if ($description !== '') {
            $tags[] = $this->propertyMeta('og:description', $description);
            $tags[] = $this->namedMeta('twitter:description', $description);
        }

        if ($image !== '') {
            $tags[] = $this->propertyMeta('og:image', $image);
            $tags[] = $this->namedMeta('twitter:image', $image);
        }

        return $tags;
    }

    private function propertyMeta(string $property, string $content): string
    {
        return '<meta property="' . $property . '" content="' . register_htmlencode($content) . '" />';
    }

    private function namedMeta(string $name, string $content): string
    {
        return '<meta name="' . $name . '" content="' . register_htmlencode($content) . '" />';
    }

    private function plainText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function firstImage(string $html): string
    {
        if (preg_match('#<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1#is', $html, $matches) !== 1) {
            return '';
        }

        return html_entity_decode(trim($matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function absoluteImage(string $image): string
    {
        $image = trim(html_entity_decode($image, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($image === '' || str_starts_with($image, 'data:') || str_starts_with($image, 'blob:')) {
            return '';
        }

        if (preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }

        $absoluteMain = $this->urlBuilder->rawAbsLink('/');
        if (preg_match('#^https?://[^/]+#i', $absoluteMain, $originMatch) !== 1) {
            return '';
        }

        if (str_starts_with($image, '//')) {
            $scheme = parse_url($originMatch[0], PHP_URL_SCHEME);
            return (\is_string($scheme) && $scheme !== '' ? $scheme : 'https') . ':' . $image;
        }

        return str_starts_with($image, '/') ? $originMatch[0] . $image : '';
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
            $escapedAuthor  = register_htmlencode($author);
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
        $rssLabel   = register_htmlencode('RSS — ' . $this->translator->trans('RSS link title'));
        $rssIcon    = $this->urlBuilder->link('/_assets/register/rss-badge.svg');

        return '<span class="footer-primary">' .
            '<span class="copyright-text">' . $copyright . '</span>' .
            '<a class="footer-rss" href="' . $this->urlBuilder->link('/rss') .
            '" aria-label="' . $rssLabel . '" title="' . $rssLabel . '">' .
            '<img src="' . $rssIcon . '" width="48" height="18" alt=""></a>' .
            '</span>' .
            '<span class="engine-credit">' . $engineCredit . '</span>';
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
