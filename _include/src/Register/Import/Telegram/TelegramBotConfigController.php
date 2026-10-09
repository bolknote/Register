<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Register\Core\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Allows the connected bot to pick up scope/owner changes without a code deployment. */
final readonly class TelegramBotConfigController implements ControllerInterface
{
    public const string PATH = '/_live/telegram/config';

    public function __construct(private TelegramSettings $settings)
    {
    }

    #[\Override]
    public function handle(Request $request): JsonResponse
    {
        $token = $this->settings->botToken();
        if (preg_match('/^[1-9][0-9]*:[A-Za-z0-9_-]{30,}$/D', $token) !== 1) {
            return $this->response(['success' => false, 'error' => 'disabled'], 404);
        }

        if (!$request->isMethod('GET')) {
            return $this->response(['success' => false, 'error' => 'method_not_allowed'], 405);
        }

        if (!hash_equals($token, $request->headers->get('X-Register-Telegram-Bot-Token', '') ?? '')) {
            return $this->response(['success' => false, 'error' => 'unauthorized'], 401);
        }

        $config = $this->settings->liveConfig();
        $relay = $this->settings->relay();

        return $this->response(['success' => true, 'config' => [
            'enabled' => $config->enabled(),
            'token' => $config->token,
            'discussionChatId' => $config->discussionChatId,
            'channelChatId' => $config->channelChatId,
            'ownerUserId' => $config->ownerTelegramUserId,
            'relayUrl' => $relay->enabled() ? $relay->url : '',
            'relayToken' => $relay->enabled() ? $relay->token : '',
        ]]);
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
