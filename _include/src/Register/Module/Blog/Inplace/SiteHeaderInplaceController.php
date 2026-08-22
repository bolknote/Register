<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use Register\Module\Blog\Model\SiteHeaderRenderer;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\AuthProvider;
use Register\Core\Pdo\DbLayer;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Saves the public-side site title and tagline editor. */
final readonly class SiteHeaderInplaceController implements ControllerInterface
{
    private const int MAX_TITLE_LENGTH = 255;

    private const int MAX_TAGLINE_LENGTH = 2000;

    public function __construct(
        private DbLayer                  $dbLayer,
        private DynamicConfigProvider    $configProvider,
        private AuthProvider             $authProvider,
        private PostInplaceTokenManager  $tokenManager,
        private SecurityAuditLogger      $auditLogger,
        private TranslatorInterface      $translator,
    ) {
    }

    #[\Override]
    public function handle(Request $request): JsonResponse
    {
        $editor = $this->authProvider->getAuthenticatedPublicUser($request);
        if ($editor === null) {
            return $this->error('Site header editing forbidden', Response::HTTP_FORBIDDEN);
        }

        if (!$editor->canEditSite) {
            return $this->error('Site header editing forbidden', Response::HTTP_FORBIDDEN);
        }

        if (!$this->tokenManager->isValidForSiteHeader($request->request->getString('inplace_token'), $editor)) {
            return $this->error('Site header editing token expired', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $title = preg_replace('/\s+/u', ' ', trim($request->request->getString('title')));
        $tagline = preg_replace('/\R/u', "\n", trim($request->request->getString('tagline')));
        if (
            !\is_string($title)
            || !\is_string($tagline)
            || $title === ''
            || mb_strlen($title) > self::MAX_TITLE_LENGTH
            || mb_strlen($tagline) > self::MAX_TAGLINE_LENGTH
        ) {
            return $this->error('Invalid site header content', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ([
            'REGISTER_SITE_NAME' => $title,
            SiteHeaderRenderer::TAGLINE_CONFIG_KEY => $tagline,
        ] as $name => $value) {
            $this->dbLayer
                ->upsert('config')
                ->setKey('name', ':name')->setParameter('name', $name)
                ->setValue('value', ':value')->setParameter('value', $value)
                ->execute();
        }

        $this->configProvider->regenerate();
        $this->auditLogger->configurationChanged($editor->id, 'REGISTER_SITE_NAME', false);
        $this->auditLogger->configurationChanged($editor->id, SiteHeaderRenderer::TAGLINE_CONFIG_KEY, false);

        return new JsonResponse([
            'success' => true,
            'title'   => $title,
            'tagline' => $tagline,
            'message' => $this->translator->trans('Site header changes saved'),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans($message),
        ], $status, ['Cache-Control' => 'no-store']);
    }
}
