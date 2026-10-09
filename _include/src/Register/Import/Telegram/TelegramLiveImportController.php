<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Psr\Log\LoggerInterface;
use Register\Core\Config\BoolProxy;
use Register\Core\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Receives a small, authenticated thread snapshot from a Telegram Serverless bot. */
final readonly class TelegramLiveImportController implements ControllerInterface
{
    public const string PATH = '/_live/telegram/comments';

    public const int MAX_BYTES = 262_144;

    public function __construct(
        private TelegramLiveImportConfig $config,
        private TelegramImportService $importer,
        private BoolProxy $premoderation,
        private LoggerInterface $logger,
        private string $baseUrl,
        private string $lockFile,
    ) {
    }

    #[\Override]
    public function handle(Request $request): JsonResponse
    {
        if (!$this->config->enabled()) {
            return $this->response(['success' => false, 'error' => 'disabled'], 404);
        }

        if (!$request->isMethod('POST')) {
            return $this->response(['success' => false, 'error' => 'method_not_allowed'], 405);
        }

        $token = $request->headers->get('X-Register-Telegram-Token', '') ?? '';
        if (!hash_equals($this->config->token, $token)) {
            return $this->response(['success' => false, 'error' => 'unauthorized'], 401);
        }

        if ($request->getContentTypeFormat() !== 'json') {
            return $this->response(['success' => false, 'error' => 'json_required'], 415);
        }

        $json = $request->getContent();
        if ($json === '' || \strlen($json) > self::MAX_BYTES) {
            return $this->response(['success' => false, 'error' => 'payload_size'], 413);
        }

        try {
            $this->validateSnapshot($json);
        } catch (\JsonException|\UnexpectedValueException $exception) {
            return $this->response(['success' => false, 'error' => 'invalid_snapshot', 'message' => $exception->getMessage()], 422);
        }

        // Serialise workers before the importer reads its idempotency mappings. No request
        // may race another request into creating a second comment for the same message.
        $lock = register_call_without_warnings(fn() => fopen($this->lockFile, 'c'));
        if (!\is_resource($lock)) {
            return $this->response(['success' => false, 'error' => 'storage_unavailable'], 503);
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return $this->response(['success' => false, 'error' => 'busy'], 503);
            }

            $report = $this->importer->importLiveSnapshot($json, !$this->premoderation->get());
            if (($report['archive']['accepted_threads'] ?? 0) !== 1) {
                return $this->response(['success' => false, 'error' => 'post_not_found'], 409);
            }

            return $this->response(['success' => true, 'changes' => $report['changes']]);
        } catch (\Throwable $exception) {
            $this->logger->error('Live Telegram comment import failed.', ['exception' => $exception]);

            return $this->response(['success' => false, 'error' => 'import_failed'], 500);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function validateSnapshot(string $json): void
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($data) || ($data['id'] ?? null) !== $this->config->discussionExportId()) {
            throw new \UnexpectedValueException('The discussion group does not match the configuration.');
        }

        $messages = $data['messages'] ?? null;
        if (!\is_array($messages) || !array_is_list($messages) || \count($messages) < 2 || \count($messages) > 65) {
            throw new \UnexpectedValueException('A snapshot must contain one root and at most 64 comments.');
        }

        foreach ($messages as $message) {
            if (!\is_array($message)
                || (isset($message['forwarded_from_id']) && $message['forwarded_from_id'] !== $this->config->channelExportId())
                || isset($message['photo']) || isset($message['file'])
            ) {
                throw new \UnexpectedValueException('A snapshot contains an unsupported message or channel.');
            }
        }

        $host = (string)parse_url($this->baseUrl, PHP_URL_HOST);
        $archive = TelegramDiscussionArchive::fromJson($json)->extract(
            static fn(string $path): array => ['content_id' => 1, 'canonical_path' => $path],
            [$host],
        );
        if ($archive['stats']['accepted_threads'] !== 1
            || $archive['stats']['comments'] !== \count($messages) - 1
        ) {
            throw new \UnexpectedValueException('All comments must belong to one channel post with a first-line link to this blog.');
        }
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
