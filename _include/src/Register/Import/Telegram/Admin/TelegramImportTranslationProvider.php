<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram\Admin;

use Register\Admin\TranslationProviderInterface;
use Register\Import\Telegram\TelegramSettings;

final class TelegramImportTranslationProvider implements TranslationProviderInterface
{
    /** @return array<string, string> */
    #[\Override]
    public function getTranslations(string $language, string $locale): array
    {
        $locale = $locale !== '' ? $locale : $language;
        $english = [
            'Telegram config' => 'Telegram',
            TelegramSettings::ENABLED => 'Import new comments',
            TelegramSettings::ENABLED . '_help' => 'Accept authenticated comments from the Serverless bot in the configured discussion group.',
            TelegramSettings::BOT_TOKEN => 'Bot API token',
            TelegramSettings::BOT_TOKEN . '_help' => 'Token from BotFather. Stored privately; its value is never displayed.',
            TelegramSettings::BRIDGE_TOKEN => 'Comment bridge key',
            TelegramSettings::BRIDGE_TOKEN . '_help' => 'Shared secret for the blog and bot: 64 lowercase hexadecimal characters. Use a cryptographically random value.',
            TelegramSettings::CHANNEL_ID => 'Channel ID',
            TelegramSettings::CHANNEL_ID . '_help' => 'Numeric Bot API ID starting with −100, not the channel username. Send /ids in the discussion group; linked_chat_id is the channel ID. Zero means unset.',
            TelegramSettings::DISCUSSION_ID => 'Discussion group ID',
            TelegramSettings::DISCUSSION_ID . '_help' => 'Numeric Bot API ID starting with −100. Send /ids in the group; chat_id is the group ID. The bot must be an administrator there.',
            TelegramSettings::OWNER_TELEGRAM_ID => 'Your Telegram user ID',
            TelegramSettings::OWNER_TELEGRAM_ID . '_help' => 'Send /start to your bot to get this positive numeric ID. Comments by this account are attributed to the selected blog author. Zero disables personal account matching.',
            TelegramSettings::AUTHOR_ID => 'Linked blog author',
            TelegramSettings::AUTHOR_ID . '_help' => 'This author supplies the name and avatar for your comments, including messages sent on behalf of the configured channel or discussion group.',
            'Default site author' => 'Default site author',
            'Telegram receiver enabled' => 'The blog is configured to accept new comments.',
            'Telegram receiver disabled' => 'The blog is not accepting new comments. Check Telegram settings.',
            'Telegram connection settings' => 'Open Telegram settings',
            'Telegram queue help' => 'The queue is stored on Telegram. Send /status to your bot to view it, or /retry to retry delivery. These commands are restricted to the configured owner.',
            'Invalid Telegram chat ID.' => 'Enter a numeric Telegram channel or supergroup ID starting with −100, or zero.',
            'Invalid Telegram user ID.' => 'Enter a positive numeric Telegram user ID, or zero.',
            'Invalid Telegram token.' => 'The Telegram token has an invalid format.',
        ];
        if ($locale !== 'ru') {
            return $english;
        }

        return array_replace($english, [
            'Telegram config' => 'Telegram',
            TelegramSettings::ENABLED => 'Импорт новых комментариев',
            TelegramSettings::ENABLED . '_help' => 'Принимать комментарии от Serverless-бота из указанной группы обсуждения.',
            TelegramSettings::BOT_TOKEN => 'Токен Bot API',
            TelegramSettings::BOT_TOKEN . '_help' => 'Токен от BotFather. Хранится отдельно от базы данных; сохранённое значение не показывается.',
            TelegramSettings::BRIDGE_TOKEN => 'Ключ импорта комментариев',
            TelegramSettings::BRIDGE_TOKEN . '_help' => 'Общий ключ для блога и бота: 64 шестнадцатеричных символа в нижнем регистре. Используйте случайное значение.',
            TelegramSettings::CHANNEL_ID => 'ID канала',
            TelegramSettings::CHANNEL_ID . '_help' => 'Числовой ID Bot API, начинающийся с −100, а не @имя. Команда /ids в группе покажет linked_chat_id — это ID канала. 0 означает «не задан».',
            TelegramSettings::DISCUSSION_ID => 'ID группы обсуждения',
            TelegramSettings::DISCUSSION_ID . '_help' => 'Числовой ID Bot API, начинающийся с −100. Команда /ids в группе покажет chat_id. Бот должен быть администратором этой группы.',
            TelegramSettings::OWNER_TELEGRAM_ID => 'Твой ID в Telegram',
            TelegramSettings::OWNER_TELEGRAM_ID . '_help' => 'Отправь боту /start — он покажет положительный числовой ID. Комментарии этого аккаунта будут связаны с выбранным автором блога. 0 отключает связь с личным аккаунтом.',
            TelegramSettings::AUTHOR_ID => 'Автор блога',
            TelegramSettings::AUTHOR_ID . '_help' => 'Его имя и аватар используются для твоих комментариев, в том числе отправленных от имени указанного канала или группы обсуждения.',
            'Default site author' => 'Основной автор сайта',
            'Telegram receiver enabled' => 'Приём новых комментариев в блоге включён.',
            'Telegram receiver disabled' => 'Приём новых комментариев выключен. Проверь настройки Telegram.',
            'Telegram connection settings' => 'Открыть настройки Telegram',
            'Telegram queue help' => 'Очередь хранится в Telegram. Команда /status в личке бота покажет её состояние, /retry повторит доставку. Эти команды доступны только указанному владельцу.',
            'Invalid Telegram chat ID.' => 'Укажи числовой ID канала или супергруппы, начинающийся с −100, либо 0.',
            'Invalid Telegram user ID.' => 'Укажи положительный числовой ID пользователя Telegram либо 0.',
            'Invalid Telegram token.' => 'Неверный формат токена Telegram.',
            'Telegram import'                    => 'Импорт из Telegram',
            'Telegram import intro'              => 'Загрузите ZIP полного экспорта обсуждения из Telegram Desktop: тогда изображения и другие вложения появятся в комментариях. Можно загрузить и один result.json — ранее сохранённые вложения останутся на месте, а ещё не загруженные будут отмечены аккуратной плашкой. Импорт можно повторять: комментарии не дублируются, а отсутствовавшие вложения восстанавливаются.',
            'Telegram import exact links'        => 'Заметки сопоставляются только по ссылкам на этот сайт в пересланных сообщениях канала. Сообщения общего чата вне веток игнорируются.',
            'Telegram export file'               => 'ZIP экспорта или файл result.json',
            'Telegram import submit'             => 'Проверить и импортировать',
            'Telegram import working'            => 'Проверяю архив и применяю изменения…',
            'Telegram import failed.'            => 'Импорт из Telegram не выполнен.',
            'Telegram import completed.'         => 'Импорт из Telegram завершён.',
            'Telegram imported identities'       => 'Связанных комментариев: {{ count }}',
            'Telegram import result'             => 'Результат импорта',
            'Telegram export upload failed.'     => 'Не удалось загрузить экспорт Telegram.',
            'Telegram export is too large or empty.' => 'Файл экспорта пуст или превышает 500 МБ.',
            'Only POST requests are allowed.'    => 'Разрешены только POST-запросы.',
            'Permission denied.'                 => 'Недостаточно прав.',
            'Invalid CSRF token.'                => 'Недействительный защитный токен.',
        ]);
    }
}
