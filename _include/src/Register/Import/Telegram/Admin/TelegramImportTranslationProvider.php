<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram\Admin;

use Register\Admin\TranslationProviderInterface;

final class TelegramImportTranslationProvider implements TranslationProviderInterface
{
    /** @return array<string, string> */
    #[\Override]
    public function getTranslations(string $language, string $locale): array
    {
        $locale = $locale !== '' ? $locale : $language;
        if ($locale !== 'ru') {
            return [];
        }

        return [
            'Telegram import'                    => 'Импорт из Telegram',
            'Telegram import intro'              => 'Загрузите ZIP полного экспорта обсуждения из Telegram Desktop: тогда изображения и другие вложения появятся в комментариях. Можно загрузить и один result.json — недоступные файлы будут отмечены аккуратной плашкой. Импорт можно повторять: комментарии не дублируются, а отсутствовавшие вложения восстанавливаются.',
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
        ];
    }
}
