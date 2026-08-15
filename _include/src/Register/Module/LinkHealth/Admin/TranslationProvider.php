<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth\Admin;

use S2\Cms\Admin\TranslationProviderInterface;

final class TranslationProvider implements TranslationProviderInterface
{
    /** @return array<string, string> */
    #[\Override]
    public function getTranslations(string $language, string $locale): array
    {
        $locale = $locale !== '' ? $locale : $language;

        return match ($locale) {
            'ru' => $this->russian(),
            'en' => $this->english(),
            default => [],
        };
    }

    /** @return array<string, string> */
    private function russian(): array
    {
        return [
            'Link health'                         => 'Ссылки',
            'Link health config'                  => 'Проверка ссылок',
            'REGISTER_LINK_AUTO_REPAIR'           => 'Автоматически заменять битые ссылки архивными копиями',
            'REGISTER_LINK_AUTO_REPAIR_help'      => 'Замена выполняется только после повторного подтверждения поломки, при найденной копии в Web Archive и неизменившейся ревизии материала.',
            'Link inventory building'             => 'Первичная инвентаризация ещё идёт. Проверки и защита удаления станут полными после её окончания.',
            'Link inventory current'              => 'Инвентаризация актуальна и обновляется при изменении материалов.',
            'Link automatic repair enabled'       => 'Автозамена архивными копиями включена.',
            'Link automatic repair disabled'      => 'Автозамена выключена; найденные копии можно применить вручную.',
            'Unique external links'               => 'Уникальные внешние',
            'Link occurrences'                    => 'Упоминания',
            'Broken links'                        => 'Битые',
            'Suspect links'                       => 'Подозрительные',
            'All links'                           => 'Все',
            'Link target'                         => 'Адрес',
            'Link state'                          => 'Состояние',
            'Link use'                            => 'Использование',
            'Link observations'                   => 'Наблюдения',
            'Link archive'                        => 'Архив',
            'Link actions'                        => 'Действия',
            'Link used in materials'              => '{{ occurrences }} раз в {{ materials }} материалах',
            'Link first seen'                     => 'Впервые в тексте: {{ time }}',
            'Link last seen'                      => 'Последний раз в тексте: {{ time }}',
            'Link last checked'                   => 'Проверена: {{ time }}',
            'Link last successful'                => 'Последний успешный ответ: {{ time }}',
            'Link next check'                     => 'Следующая проверка: {{ time }}',
            'Link never'                          => 'никогда',
            'Link HTTP status'                    => 'HTTP {{ status }}',
            'Link failures'                       => 'Сбоев подряд: {{ count }}',
            'Link archive available'              => 'Копия {{ timestamp }}',
            'Link archive missing'                => 'Копия не найдена',
            'Link archive unchecked'              => 'Архив ещё не запрашивался',
            'Link archive error'                  => 'Ошибка Web Archive',
            'Link recheck'                        => 'Перепроверить',
            'Link ignore'                         => 'Не проверять',
            'Link unignore'                       => 'Вернуть проверку',
            'Link repair'                         => 'Заменить в материалах',
            'Link recheck queued'                 => 'Перепроверка поставлена в очередь.',
            'Link ignored'                        => 'Ссылка исключена из проверок.',
            'Link restored to checks'             => 'Ссылка возвращена в проверки.',
            'Link repair queued'                  => 'Безопасная замена поставлена в очередь.',
            'Link action working'                 => 'Сохраняю…',
            'Link action failed'                  => 'Действие не выполнено.',
            'No external links'                   => 'Внешних ссылок с таким состоянием нет.',
            'Link status unknown'                 => 'Не проверена',
            'Link status healthy'                 => 'Работает',
            'Link status restricted'              => 'Доступ ограничен',
            'Link status suspect'                 => 'Подозрительна',
            'Link status broken'                  => 'Битая',
            'Link status blocked'                 => 'Небезопасный адрес',
            'Link status ignored'                 => 'Не проверять',
            'Link status skipped'                 => 'Пропущена',
            'Link inventory deletion wait'        => 'Нельзя удалять материалы, пока не закончилась первичная инвентаризация ссылок.',
            'Cannot delete linked content'        => 'Нельзя удалить «{{ target }}»: на материал ссылаются {{ sources }}.',
            'Link deletion more sources'          => 'и ещё {{ count }}',
            'Only POST requests are allowed.'     => 'Разрешены только POST-запросы.',
            'Permission denied.'                  => 'Недостаточно прав.',
            'Invalid CSRF token.'                 => 'Недействительный защитный токен.',
            'Link target not found.'              => 'Ссылка не найдена.',
            'Unknown link action.'                => 'Неизвестное действие со ссылкой.',
        ];
    }

    /** @return array<string, string> */
    private function english(): array
    {
        return [
            'Link health'                         => 'Links',
            'Link health config'                  => 'Link health',
            'REGISTER_LINK_AUTO_REPAIR'           => 'Automatically replace broken links with archived copies',
            'REGISTER_LINK_AUTO_REPAIR_help'      => 'A replacement requires repeated failure confirmation, an available Web Archive copy, and an unchanged content revision.',
            'Link inventory building'             => 'The initial inventory is still running. Checks and deletion protection become complete when it finishes.',
            'Link inventory current'              => 'The inventory is current and follows content changes.',
            'Link automatic repair enabled'       => 'Automatic archive repair is enabled.',
            'Link automatic repair disabled'      => 'Automatic repair is disabled; available copies can be applied manually.',
            'Unique external links'               => 'Unique external links',
            'Link occurrences'                    => 'Occurrences',
            'Broken links'                        => 'Broken',
            'Suspect links'                       => 'Suspect',
            'All links'                           => 'All',
            'Link target'                         => 'Target',
            'Link state'                          => 'State',
            'Link use'                            => 'Use',
            'Link observations'                   => 'Observations',
            'Link archive'                        => 'Archive',
            'Link actions'                        => 'Actions',
            'Link used in materials'              => '{{ occurrences }} occurrences in {{ materials }} documents',
            'Link first seen'                     => 'First seen in content: {{ time }}',
            'Link last seen'                      => 'Last seen in content: {{ time }}',
            'Link last checked'                   => 'Checked: {{ time }}',
            'Link last successful'                => 'Last successful response: {{ time }}',
            'Link next check'                     => 'Next check: {{ time }}',
            'Link never'                          => 'never',
            'Link HTTP status'                    => 'HTTP {{ status }}',
            'Link failures'                       => 'Consecutive failures: {{ count }}',
            'Link archive available'              => 'Snapshot {{ timestamp }}',
            'Link archive missing'                => 'No snapshot found',
            'Link archive unchecked'              => 'Archive not queried yet',
            'Link archive error'                  => 'Web Archive error',
            'Link recheck'                        => 'Recheck',
            'Link ignore'                         => 'Ignore',
            'Link unignore'                       => 'Resume checks',
            'Link repair'                         => 'Replace in content',
            'Link recheck queued'                 => 'The recheck has been queued.',
            'Link ignored'                        => 'The link has been excluded from checks.',
            'Link restored to checks'             => 'The link has been returned to checks.',
            'Link repair queued'                  => 'Safe replacement has been queued.',
            'Link action working'                 => 'Saving…',
            'Link action failed'                  => 'The action failed.',
            'No external links'                   => 'No external links have this state.',
            'Link status unknown'                 => 'Unchecked',
            'Link status healthy'                 => 'Healthy',
            'Link status restricted'              => 'Restricted',
            'Link status suspect'                 => 'Suspect',
            'Link status broken'                  => 'Broken',
            'Link status blocked'                 => 'Unsafe address',
            'Link status ignored'                 => 'Ignored',
            'Link status skipped'                 => 'Skipped',
            'Link inventory deletion wait'        => 'Content cannot be deleted until the initial link inventory is complete.',
            'Cannot delete linked content'        => '“{{ target }}” cannot be deleted because it is linked from {{ sources }}.',
            'Link deletion more sources'          => 'and {{ count }} more',
        ];
    }
}
