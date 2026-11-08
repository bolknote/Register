<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Psr\Log\LoggerInterface;
use Register\Core\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** The bot downloads avatars; this receiver never contacts Telegram. */
final readonly class TelegramAvatarController implements ControllerInterface
{
    public const string PATH = '/_live/telegram/avatars';

    public function __construct(
        private TelegramLiveImportConfig $config,
        private TelegramAvatarService $avatars,
        private string $lockFile,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function handle(Request $request): JsonResponse
    {
        if (!$this->config->enabled()) {
            return $this->response(['success' => false, 'error' => 'disabled'], 404);
        }

        if (!hash_equals($this->config->token, $request->headers->get('X-Register-Telegram-Token', '') ?? '')) {
            return $this->response(['success' => false, 'error' => 'unauthorized'], 401);
        }

        $scope = $this->config->discussionExportId();
        try {
            if ($request->isMethod('GET')) {
                if ($request->query->getInt('chat_id') !== $scope) {
                    throw new \UnexpectedValueException('The avatar discussion does not match.');
                }

                $filter = $request->query->getString('authors');
                $authors = $filter === '' ? [] : explode(',', $filter);
                if (\count($authors) > 20) {
                    throw new \UnexpectedValueException('Too many avatar authors.');
                }

                foreach ($authors as $author) {
                    if (!TelegramAvatarService::validAuthor($author)) {
                        throw new \UnexpectedValueException('The avatar author is invalid.');
                    }
                }

                return $this->response(['success' => true, 'authors' => $this->avatars->requests($scope, $authors)]);
            }

            if (!$request->isMethod('POST')) {
                return $this->response(['success' => false, 'error' => 'method_not_allowed'], 405);
            }

            $bytes = $request->getContent();
            if (\strlen($bytes) > TelegramAvatarStorage::MAX_BYTES) {
                return $this->response(['success' => false, 'error' => 'payload_size'], 413);
            }

            $unavailable = $request->getContentTypeFormat() === 'json';
            $data = $unavailable ? json_decode($bytes, true, 8, JSON_THROW_ON_ERROR) : [
                'chat_id' => filter_var($request->headers->get('X-Register-Telegram-Chat'), FILTER_VALIDATE_INT),
                'author' => $request->headers->get('X-Register-Telegram-Author'),
            ];
            if (!\is_array($data) || ($data['chat_id'] ?? null) !== $scope || !\is_string($data['author'] ?? null)
                || !TelegramAvatarService::validAuthor($data['author'])
                || ($unavailable && ($data['unavailable'] ?? null) !== true)
                || (!$unavailable && $request->headers->get('Content-Type') !== 'application/octet-stream')
            ) {
                throw new \UnexpectedValueException('The avatar request is invalid.');
            }

            $lock = register_call_without_warnings(fn() => fopen($this->lockFile, 'c'));
            if (!\is_resource($lock)) {
                throw new \RuntimeException('The avatar import lock is unavailable.');
            }

            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    return $this->response(['success' => false, 'error' => 'busy'], 503);
                }

                if ($unavailable) {
                    $this->avatars->unavailable($scope, $data['author']);
                    return $this->response(['success' => true, 'filled' => 0]);
                }

                return $this->response(['success' => true, 'filled' => $this->avatars->accept(
                    $scope, $data['author'], $request->headers->get('X-Register-Telegram-Photo', '') ?? '', $bytes,
                )]);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\UnexpectedValueException|\JsonException) {
            return $this->response(['success' => false, 'error' => 'invalid_avatar'], 422);
        } catch (\Throwable $exception) {
            $this->logger->error('Telegram avatar import failed.', ['exception' => $exception]);
            return $this->response(['success' => false, 'error' => 'avatar_unavailable'], 503);
        }
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
