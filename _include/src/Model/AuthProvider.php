<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use S2\Cms\Config\IntProxy;
use S2\Cms\Model\Comment\CommentModerator;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;
use S2\Cms\Pdo\DbLayerException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

readonly class AuthProvider
{
    public function __construct(
        private DbLayer $dbLayer,
        private string  $cookieName,
        private IntProxy $loginTimeoutMinutes,
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
     * Authenticates the short-lived public-side moderator cookie issued by the admin login.
     *
     * @throws DbLayerException
     * @throws BadRequestException
     */
    public function getAuthenticatedCommentModerator(Request $request): ?CommentModerator
    {
        $cookie = $request->cookies->get($this->cookieName . '_c', '');
        if ($cookie === '') {
            return null;
        }

        $result = $this->dbLayer
            ->select('u.login, u.email, u.hide_comments, u.edit_comments')
            ->from('users AS u')
            ->innerJoin('users_online AS o', 'o.login = u.login')
            ->where('o.comment_cookie = :cookie')
            ->setParameter('cookie', $cookie)
            ->andWhere('o.time >= :min_time')
            ->setParameter('min_time', time() - max(1, $this->loginTimeoutMinutes->get()) * 60)
            ->andWhere('o.ip = :ip')
            ->setParameter('ip', $request->getClientIp())
            ->andWhere('(u.hide_comments = 1 OR u.edit_comments = 1)')
            ->limit(1)
            ->execute()
        ;

        $row = $result->fetchAssoc();
        if ($row === false) {
            return null;
        }

        return new CommentModerator(
            (string)$row['login'],
            (string)$row['email'],
            (bool)$row['hide_comments'],
            (bool)$row['edit_comments'],
            hash('sha256', $cookie),
        );
    }
}
