<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use S2\Cms\Model\Comment\CommentModerator;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;
use S2\Cms\Pdo\DbLayerException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

readonly class AuthProvider
{
    private const string REQUEST_USER_ATTRIBUTE = '_register_authenticated_public_user';

    public function __construct(
        private DbLayer $dbLayer,
        private string  $cookieName,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function isOnline(string $email): bool
    {
        $result = $this->dbLayer
            ->select('COUNT(*)')
            ->from('users AS u')
            ->innerJoin('users_online AS o', 'o.login = u.login')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->execute()
        ;

        $count = $result->result();

        return $count > 0;
    }

    /**
     * @throws DbLayerException
     * @throws BadRequestException
     */
    public function getAuthenticatedModeratorEmail(Request $request): ?string
    {
        $moderator = $this->getAuthenticatedCommentModerator($request);
        if (!$moderator instanceof CommentModerator) {
            return null;
        }

        if (!$moderator->canEdit) {
            return null;
        }

        return $moderator->email;
    }

    /**
     * Authenticates the public-side cookie and checks the actual administrator permission.
     *
     * @throws DbLayerException
     */
    public function isAuthenticatedAdministrator(Request $request): bool
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof \S2\Cms\Model\AuthenticatedPublicUser) {
            return false;
        }

        return $user->isAdministrator;
    }

    /**
     * Authenticates the public-side moderator cookie issued by the admin login.
     *
     * @throws DbLayerException
     * @throws BadRequestException
     */
    public function getAuthenticatedCommentModerator(Request $request): ?CommentModerator
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof \S2\Cms\Model\AuthenticatedPublicUser || (!$user->canHideComments && !$user->canEditComments)) {
            return null;
        }

        return new CommentModerator(
            $user->login,
            $user->email,
            $user->canHideComments,
            $user->canEditComments,
            $user->sessionHash,
        );
    }

    /**
     * @throws DbLayerException
     */
    private function getAuthenticatedUser(Request $request): ?AuthenticatedPublicUser
    {
        if ($request->attributes->has(self::REQUEST_USER_ATTRIBUTE)) {
            $cachedUser = $request->attributes->get(self::REQUEST_USER_ATTRIBUTE);

            return $cachedUser instanceof AuthenticatedPublicUser ? $cachedUser : null;
        }

        $cookie = $request->cookies->get($this->cookieName . '_c', '');
        if ($cookie === '') {
            $request->attributes->set(self::REQUEST_USER_ATTRIBUTE, false);
            return null;
        }

        $result = $this->dbLayer
            ->select('u.login, u.email, u.hide_comments, u.edit_comments, u.edit_users')
            ->from('users AS u')
            ->innerJoin('users_online AS o', 'o.login = u.login')
            ->where('o.comment_cookie = :cookie')
            ->setParameter('cookie', AuthTokenHasher::comment($cookie))
            ->limit(1)
            ->execute()
        ;

        $row = $result->fetchAssoc();
        if ($row === false) {
            $request->attributes->set(self::REQUEST_USER_ATTRIBUTE, false);
            return null;
        }

        $user = new AuthenticatedPublicUser(
            (string)$row['login'],
            (string)$row['email'],
            (bool)$row['hide_comments'],
            (bool)$row['edit_comments'],
            (bool)$row['edit_users'],
            hash('sha256', $cookie),
        );
        $request->attributes->set(self::REQUEST_USER_ATTRIBUTE, $user);

        return $user;
    }
}
