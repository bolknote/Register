<?php
/**
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin;

use Register\Content\ContentType;
use Register\Http\ContentSecurityPolicy;
use Register\Module\Blog\Module as BlogModule;
use Register\Url\ContentUrlCollisionException;
use Register\AdminYard\Translator;
use Register\AdminYard\Translator as T;
use Register\Core\Admin\Event\AdminAjaxControllerMapEvent;
use Register\Core\Admin\Picture\PictureFileNameHelper;
use Register\Core\Admin\Picture\PictureManager;
use Register\Core\Admin\Picture\PictureReserveManager;
use Register\Core\Extensions\ExtensionManagerAdapter;
use Register\Core\Framework\Container;
use Register\Core\Framework\Container as C;
use Register\Core\Framework\Exception\AccessDeniedException;
use Register\Core\Framework\Exception\NotFoundException;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Model\ArticleManager;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\AuthManager;
use Register\Core\Model\PermissionChecker;
use Register\Core\Model\PermissionChecker as P;
use Register\Core\Security\Http\AdminMutationGuard;
use Register\Core\Security\Http\SameOriginRequestGuard;
use Register\Core\Template\HtmlTemplateProvider;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\JsonResponse as Json;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Request as R;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Register\Core\Pdo\DbLayerException;

class AdminAjaxRequestHandler
{
    private const int MAX_UPLOAD_FILES = 20;

    public function __construct(
        public RequestStack             $requestStack,
        public AuthManager              $authManager,
        public PermissionChecker        $permissionChecker,
        public SameOriginRequestGuard   $sameOriginRequestGuard,
        public AdminMutationGuard       $mutationGuard,
        public Translator               $translator,
        public EventDispatcherInterface $eventDispatcher,
        public Container                $container,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function handle(Request $request): Response
    {
        foreach ($this->container->getByTagIfInstantiated(StatefulServiceInterface::class) as $service) {
            $service->clearState();
        }

        $request->setSession(new Session());
        $request->attributes->set(AuthManager::FORCE_AJAX_RESPONSE, true);

        $this->requestStack->push($request);

        $originViolation = $this->sameOriginRequestGuard->violation($request);
        if ($originViolation !== null) {
            $response = new Json([
                'success' => false,
                'message' => $this->translator->trans($originViolation),
            ], Response::HTTP_FORBIDDEN);
            ContentSecurityPolicy::applyToAdmin($response);
            $this->requestStack->pop();

            return $response;
        }

        $response = $this->authManager->checkAuth($request);
        if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
            $this->requestStack->pop();

            ContentSecurityPolicy::applyToAdmin($response);

            return $response;
        }

        $controllerMap = [
            // Articles tree
            'move'                => static function (P $p, R $r, C $c, T $_t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$r->request->has('source_id') || !$r->request->has('new_parent_id') || !$r->request->has('new_pos')) {
                    return new Json(['success' => false, 'message' => 'Parameters "source_id", "new_parent_id" and "new_pos" are required.'], Response::HTTP_BAD_REQUEST);
                }

                $am = $c->get(ArticleManager::class);
                $am->moveBranch(
                    (int)$r->request->get('source_id'),
                    (int)$r->request->get('new_parent_id'),
                    (int)$r->request->get('new_pos'),
                    $r->request->getString('csrf_token')
                );

                return new Json(['success' => true]);
            },
            'delete'              => static function (P $p, R $r, C $c, T $_t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$r->query->has('id')) {
                    return new Json(['success' => false, 'message' => 'Parameter "id" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $am = $c->get(ArticleManager::class);
                $am->deleteBranch($r->query->getInt('id'), $r->request->getString('csrf_token'));

                return new Json(['success' => true]);
            },
            'create'              => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGrantedAny(P::PERMISSION_CREATE_ARTICLES)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('id') || !$r->request->has('title')) {
                    return new Json(['success' => false, 'message' => 'Parameters "id" and "title" are required.'], Response::HTTP_BAD_REQUEST);
                }

                $am        = $c->get(ArticleManager::class);
                $parentId  = $r->query->getInt('id');
                $newId     = $am->createArticle($parentId, $r->request->getString('title'), $r->request->getString('csrf_token'));

                return new Json(['success' => true, 'id' => $newId, 'csrfToken' => $am->getCsrfToken($newId)]);
            },
            'rename'              => static function (P $p, R $r, C $c, T $_t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$r->query->has('id') || !$r->request->has('title')) {
                    return new Json(['success' => false, 'message' => 'Parameters "id" and "title" are required.'], Response::HTTP_BAD_REQUEST);
                }

                $am = $c->get(ArticleManager::class);
                $am->renameArticle($r->query->getInt('id'), $r->request->getString('title'), $r->request->getString('csrf_token'));

                return new Json(['success' => true]);
            },
            'load_tree'           => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if (!$p->isGranted(P::PERMISSION_VIEW)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('id')) {
                    return new Json(['success' => false, 'message' => 'Parameter "id" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $am = $c->get(ArticleManager::class);

                return new Json($am->getChildBranches((int)$r->query->get('id'), $r->query->get('search')));
            },


            // Extensions
            'flip_extension'      => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_EDIT_USERS)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('id')) {
                    return new Json(['success' => false, 'message' => 'Parameter "id" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $em    = $c->get(ExtensionManagerAdapter::class);
                $error = $em->flipExtension($r->query->getString('id'), $r->request->getString('csrf_token'));

                return new Json(['success' => $error === null, 'message' => $error]);
            },
            'install_extension'   => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_EDIT_USERS)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('id')) {
                    return new Json(['success' => false, 'message' => 'Parameter "id" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $em     = $c->get(ExtensionManagerAdapter::class);
                $errors = $em->installExtension($r->query->getString('id'), $r->request->getString('csrf_token'));

                return new Json(['success' => $errors === [], 'message' => implode("\n", $errors)]);
            },
            'uninstall_extension' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_EDIT_USERS)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('id')) {
                    return new Json(['success' => false, 'message' => 'Parameter "id" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $em    = $c->get(ExtensionManagerAdapter::class);
                $error = $em->uninstallExtension($r->query->getString('id'), $r->request->getString('csrf_token'));

                return new Json(['success' => $error === null, 'message' => $error]);
            },

            // pictures
            'preview' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\Response {
                if (!$p->isGranted(P::PERMISSION_VIEW)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('file')) {
                    return new Json(['success' => false, 'message' => 'Parameter "file" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $file = (string)$r->query->get('file');
                if (str_contains($file, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid file name.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);

                $response = $pictureManager->getThumbnailResponse($file, 200);
                $response->setPublic();
                $response->setExpires(new \DateTimeImmutable('1 year'));

                return $response;
            },

            'load_folders' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if (!$p->isGranted(P::PERMISSION_VIEW)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                $path = $r->query->getString('path');
                if (str_contains($path, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid path.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);

                try {
                    return new Json($pictureManager->getDirContentRecursive($path));
                } catch (\RuntimeException $runtimeException) {
                    return new Json(['success' => false, 'message' => $runtimeException->getMessage()], self::httpStatus($runtimeException));
                }
            },

            'picture_csrf_token' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_VIEW)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->request->has('path')) {
                    return new Json(['success' => false, 'message' => 'Parameter "path" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $path = $r->request->getString('path');
                if (str_contains($path, '..') || str_contains($path, "\0")) {
                    return new Json(['success' => false, 'message' => 'Invalid path.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);

                return new Json(['success' => true, 'csrf_token' => $pictureManager->getFolderCsrfToken($path)]);
            },

            'create_subfolder' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_CREATE_ARTICLES)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('path') || !$r->query->has('name')) {
                    return new Json(['success' => false, 'message' => 'Parameters "path" and "name" are required.'], Response::HTTP_BAD_REQUEST);
                }

                $path = $r->query->getString('path');
                if (str_contains($path, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid path.'], Response::HTTP_BAD_REQUEST);
                }

                $name = $r->query->getString('name');
                if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
                    return new Json(['success' => false, 'message' => 'Invalid name.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);
                try {
                    $pictureManager->assertFolderCsrfToken($path, (string)$r->request->get('csrf_token', ''));
                    $newName = $pictureManager->createSubfolder($path, $name);
                    $newPath = $path . '/' . $newName;
                    return new Json([
                        'success'    => true,
                        'name'       => $newName,
                        'path'       => $newPath,
                        'csrf_token' => $pictureManager->getFolderCsrfToken($newPath),
                    ]);
                } catch (\RuntimeException $runtimeException) {
                    return new Json(['success' => false, 'message' => $runtimeException->getMessage()], self::httpStatus($runtimeException));
                }
            },

            'delete_folder' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_EDIT_SITE)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('path')) {
                    return new Json(['success' => false, 'message' => 'Parameter "path" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $path = $r->query->getString('path');
                if (str_contains($path, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid path.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);
                $pictureManager->assertFolderCsrfToken($path, (string)$r->request->get('csrf_token', ''));

                if ($path !== '') {
                    $pictureManager->deleteFolder($path);
                }

                return new Json(['success' => true]);
            },

            'delete_files' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_EDIT_SITE)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('path') || !$r->query->has('fname')) {
                    return new Json(['success' => false, 'message' => 'Parameters "path" and "fname" are required.'], Response::HTTP_BAD_REQUEST);
                }

                try {
                    $fileNames = $r->query->all('fname');
                } catch (BadRequestException) {
                    return new Json(['success' => false, 'message' => 'Parameter "fname" must be an array.'], Response::HTTP_BAD_REQUEST);
                }

                $dir = $r->query->getString('path');
                if (str_contains($dir, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid path.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);
                $pictureManager->assertFolderCsrfToken($dir, (string)$r->request->get('csrf_token', ''));

                foreach ($fileNames as $fileName) {
                    $path = $dir . '/' . $fileName;
                    while (str_contains($path, '..')) {
                        $path = str_replace('..', '', $path);
                    }

                    $pictureManager->deleteFile($path);
                }

                return new Json(['success' => true]);
            },

            'rename_folder' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_EDIT_SITE)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('path') || !$r->query->has('name')) {
                    return new Json(['success' => false, 'message' => 'Parameters "path" and "name" are required.'], Response::HTTP_BAD_REQUEST);
                }

                $path = $r->query->getString('path');
                if (str_contains($path, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid path.'], Response::HTTP_BAD_REQUEST);
                }

                $name = $r->query->getString('name');
                if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
                    return new Json(['success' => false, 'message' => 'Invalid name.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);
                try {
                    $pictureManager->assertFolderCsrfToken($path, (string)$r->request->get('csrf_token', ''));
                    $newName = $pictureManager->renameFolder($path, $name);
                    return new Json([
                        'success'    => true,
                        'new_path'   => $newName,
                        'csrf_token' => $pictureManager->getFolderCsrfToken($newName),
                    ]);
                } catch (\RuntimeException $runtimeException) {
                    return new Json(['success' => false, 'message' => $runtimeException->getMessage()], self::httpStatus($runtimeException));
                }
            },

            'rename_file' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_EDIT_SITE)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('path') || !$r->query->has('name')) {
                    return new Json(['success' => false, 'message' => 'Parameters "path" and "name" are required.'], Response::HTTP_BAD_REQUEST);
                }

                $path = $r->query->getString('path');
                if (str_contains($path, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid path.'], Response::HTTP_BAD_REQUEST);
                }

                $filename = $r->query->getString('name');
                if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
                    return new Json(['success' => false, 'message' => 'Invalid name.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);
                try {
                    $pictureManager->assertFileCsrfToken($path, (string)$r->request->get('csrf_token', ''));
                    $newName = $pictureManager->renameFile($path, $filename);
                    return new Json(['success' => true, 'new_name' => $newName]);
                } catch (\RuntimeException $runtimeException) {
                    return new Json(['success' => false, 'message' => $runtimeException->getMessage()], self::httpStatus($runtimeException));
                }
            },

            'move_folder' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_EDIT_SITE)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('spath') || !$r->query->has('dpath')) {
                    return new Json(['success' => false, 'message' => 'Parameters "spath" and "dpath" are required.'], Response::HTTP_BAD_REQUEST);
                }

                $sourcePath = $r->query->getString('spath');
                if (str_contains($sourcePath, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid source path.'], Response::HTTP_BAD_REQUEST);
                }

                $destinationPath = $r->query->getString('dpath');
                if (str_contains($destinationPath, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid destination path.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);
                try {
                    $pictureManager->assertFolderCsrfToken($sourcePath, (string)$r->request->get('csrf_token', ''));
                    $pictureManager->assertFolderCsrfToken($destinationPath, (string)$r->request->get('destination_csrf_token', ''));
                    $newPath = $pictureManager->moveFolder($sourcePath, $destinationPath);
                    return new Json([
                        'success'    => true,
                        'new_path'   => $newPath,
                        'csrf_token' => $pictureManager->getFolderCsrfToken($newPath),
                    ]);
                } catch (\RuntimeException $runtimeException) {
                    return new Json(['success' => false, 'message' => $runtimeException->getMessage()], self::httpStatus($runtimeException));
                }
            },

            'move_files' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_EDIT_SITE)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (
                    !$r->query->has('spath')
                    || !$r->query->has('dpath')
                    || !$r->query->has('fname')
                ) {
                    return new Json(['success' => false, 'message' => 'Parameters "spath", "dpath", and "fname" are required.'], Response::HTTP_BAD_REQUEST);
                }

                try {
                    $fileNames = $r->query->all('fname');
                } catch (BadRequestException) {
                    return new Json(['success' => false, 'message' => 'Parameter "fname" must be an array.'], Response::HTTP_BAD_REQUEST);
                }

                $sourcePath = $r->query->getString('spath');
                if (str_contains($sourcePath, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid source path.'], Response::HTTP_BAD_REQUEST);
                }

                $destinationPath = $r->query->getString('dpath');
                if (str_contains($destinationPath, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid destination path.'], Response::HTTP_BAD_REQUEST);
                }

                foreach ($fileNames as $fileName) {
                    if (str_contains($fileName, '..')) {
                        return new Json(['success' => false, 'message' => 'Invalid file name.'], Response::HTTP_BAD_REQUEST);
                    }
                }

                $pictureManager = $c->get(PictureManager::class);
                try {
                    $pictureManager->assertFolderCsrfToken($sourcePath, (string)$r->request->get('csrf_token', ''));
                    $pictureManager->assertFolderCsrfToken($destinationPath, (string)$r->request->get('destination_csrf_token', ''));
                    $pictureManager->moveFiles($sourcePath, $destinationPath, $fileNames);
                    return new Json(['success' => true]);
                } catch (\RuntimeException $runtimeException) {
                    return new Json(['success' => false, 'message' => $runtimeException->getMessage()], self::httpStatus($runtimeException));
                }
            },

            'load_files' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if (!$p->isGranted(P::PERMISSION_VIEW)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('path')) {
                    return new Json(['success' => false, 'message' => 'Parameter "path" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $path = $r->query->getString('path');
                if (str_contains($path, '..')) {
                    return new Json(['success' => false, 'message' => 'Invalid path.'], Response::HTTP_BAD_REQUEST);
                }

                $pictureManager = $c->get(PictureManager::class);
                $files          = $pictureManager->getFiles($path);
                return new Json($files);
            },

            'reserve_image' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_CREATE_ARTICLES)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->request->has('dir') || !$r->request->has('name')) {
                    return new Json(['success' => false, 'message' => 'Parameters "dir" and "name" are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $path = $r->request->getString('dir');
                if (str_contains($path, '..') || str_contains($path, "\0")) {
                    return new Json(['success' => false, 'message' => 'Invalid dir.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $name = (string)$r->request->get('name');
                if ($name === '') {
                    return new Json(['success' => false, 'message' => 'Invalid file name.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $pictureManager = $c->get(PictureManager::class);
                $pictureManager->assertFolderCsrfToken($path, (string)$r->request->get('csrf_token', ''));

                $reserveManager = $c->get(PictureReserveManager::class);

                try {
                    $reserve = $reserveManager->reserveFileName($path, $name);
                } catch (\RuntimeException $runtimeException) {
                    return new Json(['success' => false, 'message' => $runtimeException->getMessage()], self::httpStatus($runtimeException));
                }

                $filePath = $path . '/' . $reserve['name'];

                return new Json([
                    'success'   => true,
                    'file_path' => $c->getStringParameter('image_path') . $filePath,
                    'dir'       => $path,
                    'name'      => $reserve['name'],
                    'token'     => $reserve['token'],
                ]);
            },

            'upload' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if ($r->getRealMethod() !== 'POST') {
                    return new Json(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGranted(P::PERMISSION_CREATE_ARTICLES)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->request->has('dir')) {
                    return new Json(['success' => false, 'message' => $t->trans('No POST data')], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $path = $r->request->getString('dir');
                if (str_contains($path, '..') || str_contains($path, "\0")) {
                    return new Json(['success' => false, 'message' => 'Invalid dir.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                if (!$r->files->has('pictures')) {
                    return new Json(['success' => false, 'message' => $t->trans('No file')], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                try {
                    $uploadedFiles = $r->files->all('pictures');
                } catch (BadRequestException) {
                    return new Json(['success' => false, 'message' => $t->trans('Invalid files')], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                if (\count($uploadedFiles) === 0) {
                    return new Json(['success' => false, 'message' => $t->trans('Empty files')], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                if (\count($uploadedFiles) > self::MAX_UPLOAD_FILES) {
                    return new Json([
                        'success' => false,
                        'message' => 'Too many files. Upload at most ' . self::MAX_UPLOAD_FILES . ' files at once.',
                    ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
                }

                foreach ($uploadedFiles as $uploadedFile) {
                    if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        return new Json(['success' => false, 'message' => $t->trans('Invalid file')], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }
                }

                /** @var list<\Symfony\Component\HttpFoundation\File\UploadedFile> $uploadedFiles */

                try {
                    $c->get(PictureFileNameHelper::class)->assertSafeBatchSize($uploadedFiles);
                } catch (\RuntimeException $runtimeException) {
                    return new Json(['success' => false, 'message' => $runtimeException->getMessage()], self::httpStatus($runtimeException));
                }

                $pictureManager = $c->get(PictureManager::class);
                $pictureManager->assertFolderCsrfToken($path, (string)$r->request->get('csrf_token', ''));

                $reserveManager = $c->get(PictureReserveManager::class);

                if ($r->request->has('token') && $r->request->has('name')) {
                    if (\count($uploadedFiles) !== 1) {
                        return new Json(['success' => false, 'message' => 'Only one file can be uploaded with a reserved name.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $token = (string)$r->request->get('token');
                    $name  = (string)$r->request->get('name');
                    if ($token === '' || $name === '') {
                        return new Json(['success' => false, 'message' => 'Invalid reserve token or name.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    if (!$reserveManager->validateReserveToken($path, $name, $token)) {
                        return new Json(['success' => false, 'message' => 'Reserve token mismatch.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    try {
                        $storedName = $pictureManager->processUploadedFileWithReservedName(
                            $uploadedFiles[0],
                            $path,
                            $name,
                            $r->request->getBoolean('create_dir')
                        );
                        $reserveManager->clearReserve($path, $name);
                    } catch (\RuntimeException $e) {
                        if ($e->getCode() === Response::HTTP_CONFLICT) {
                            $reserveManager->clearReserve($path, $name);
                        }

                        return new Json(['success' => false, 'message' => $e->getMessage()], self::httpStatus($e));
                    }

                    return new Json([
                        'success'   => true,
                        'file_path' => $c->getStringParameter('image_path') . $storedName,
                        ...$r->request->has('return_image_info') ? ['image_info' => $pictureManager->getImageInfo($storedName)] : [],
                    ]);
                }

                $errors = [];

                $lastFileName = null;
                foreach ($uploadedFiles as $uploadedFile) {
                    try {
                        $lastFileName = $pictureManager->processUploadedFile($uploadedFile, $path, $r->request->getBoolean('create_dir'));
                    } catch (\RuntimeException $e) {
                        $errors[] = $e->getMessage();
                    }
                }

                if (\count($errors) > 0) {
                    return new Json(['success' => false, 'errors' => $errors]);
                }

                if ($lastFileName === null) {
                    return new Json(['success' => false, 'message' => $t->trans('No file')], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                return new Json([
                    'success'   => true,
                    'file_path' => $c->getStringParameter('image_path') . $lastFileName,
                    ...$r->request->has('return_image_info') ? ['image_info' => $pictureManager->getImageInfo($lastFileName)] : [],
                ]);
            },

            // article helpers
            'load_template' => static function (P $p, R $r, C $c, T $t): \Symfony\Component\HttpFoundation\JsonResponse {
                if (!$p->isGranted(P::PERMISSION_CREATE_ARTICLES)) {
                    return new Json(['success' => false, 'message' => $t->trans('No permission')], Response::HTTP_FORBIDDEN);
                }

                if (!$r->query->has('article_id') && !$r->query->has('template_id')) {
                    return new Json(['success' => false, 'message' => 'One of parameters "article_id" or "template_id" is required.'], Response::HTTP_BAD_REQUEST);
                }

                $templateId = $r->query->getString('template_id');
                if ($templateId === '') {
                    $articleId = $r->query->getInt('article_id');
                    $articleProvider = $c->get(ArticleProvider::class);
                    $templateId      = $articleProvider->findInheritedTemplate($articleId, false);
                }

                if ($templateId === '') {
                    $templateId = 'site.php';
                }

                $contentType = $r->query->getString('content_type');
                if (!\in_array($contentType, ['', ContentType::PAGE->value, ContentType::POST->value], true)) {
                    return new Json([
                        'success' => false,
                        'message' => 'Unsupported content type.',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $resourceOwner = $contentType === ContentType::POST->value ? BlogModule::class : null;

                $htmlTemplateProvider = $c->get(HtmlTemplateProvider::class);

                try {
                    $template = $htmlTemplateProvider->getRawTemplateContent($templateId, $resourceOwner);
                } catch (\RuntimeException) {
                    $template = '';
                }

                if ($template === '') {
                    $errorMessage = $t->trans('Preview template not found', ['{{ template }}' => $templateId]);
                    return new Json([
                        'success'         => false,
                        'preview_message' => $errorMessage,
                        'template_id'     => $templateId,
                    ]);
                }

                return new Json(['success' => true, 'template' => $template]);
            },
        ];

        $this->eventDispatcher->dispatch($event = new AdminAjaxControllerMapEvent($controllerMap, [
            'load_tree',
            'preview',
            'load_folders',
            'load_files',
            'load_template',
        ]));

        $action     = $request->query->getString('action', $request->request->getString('action'));
        $controller = $event->controllerMap[$action] ?? (static fn(P $_p, R $_r, C $_c, T $_t): \Symfony\Component\HttpFoundation\JsonResponse => new Json(['success' => false, 'message' => 'Unknown action.'], Response::HTTP_BAD_REQUEST));

        if (!$this->mutationGuard->isPost($request) && !$event->allowsGet($action)) {
            $response = new Json([
                'success' => false,
                'message' => $this->translator->trans('Only POST requests are allowed.'),
            ], Response::HTTP_METHOD_NOT_ALLOWED);
            $response->headers->set('Allow', Request::METHOD_POST);
            $this->authManager->renewPersistentCookies($request, $response);
            ContentSecurityPolicy::applyToAdmin($response);
            $this->requestStack->pop();

            return $response;
        }

        try {
            $response = $controller($this->permissionChecker, $request, $this->container, $this->translator);
        } catch (ContentUrlCollisionException $e) {
            $response = new Json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException $e) {
            $response = new Json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (NotFoundException $e) {
            $response = new Json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        $this->authManager->renewPersistentCookies($request, $response);
        ContentSecurityPolicy::applyToAdmin($response);
        $this->requestStack->pop();

        return $response;
    }

    private static function httpStatus(\Throwable $throwable): int
    {
        $code = $throwable->getCode();
        return \is_int($code) && $code >= 100 && $code <= 599
            ? $code
            : Response::HTTP_INTERNAL_SERVER_ERROR;
    }
}
