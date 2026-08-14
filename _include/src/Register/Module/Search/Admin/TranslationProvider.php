<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace Register\Module\Search\Admin;

use S2\Cms\Admin\TranslationProviderInterface;

class TranslationProvider implements TranslationProviderInterface
{
    /**
     * @return array<mixed>
     */
    #[\Override]
    public function getTranslations(string $language, string $locale): array
    {
        return match ($locale) {
            'ru' => [
                'Search config'                        => 'Поиск',
                'S2_SEARCH_QUICK'                      => 'Быстрый поиск',
                'S2_SEARCH_QUICK_help'                 => 'По мере набора поискового запроса показывать подсказки с совпадающими заголовками.',
                'S2_SEARCH_RECOMMENDATIONS_LIMIT'      => 'Объем рекомендаций',
                'S2_SEARCH_RECOMMENDATIONS_LIMIT_help' => 'Максимальное количество рекомендаций. 0 отключает рекомендации совсем.',
                'Search index'                         => 'Поисковый индекс',
                'Search current'                       => 'Индекс актуален и обновляется автоматически.',
                'Search repairing'                     => 'Индекс автоматически восстанавливается после HTTP-ответов.',
                'Search updating'                      => 'Автоматически обрабатывается {{ pending }} изменение.|Автоматически обрабатываются {{ pending }} изменения.|Автоматически обрабатываются {{ pending }} изменений.',
                'Search repair required'               => 'Индекс расходится с опубликованными материалами; автоматическое восстановление не удалось запустить.',
                'Indexed documents'                    => 'Материалы: {{ indexed }} из {{ expected }}',
                'Index storage'                        => 'Технически: {{ rows }} строк, {{ size }}',
                'Repair index'                         => 'Восстановить индекс',
                'Repair index title'                   => 'Поставить полную сверку индекса в очередь Register. Работа продолжится после HTTP-ответов, закрытия страницы или временной ошибки.',
                'Repair scheduled'                     => 'восстановление продолжится автоматически',
                'Repair scheduling failed'             => 'не удалось запустить восстановление',
            ],
            'en' => [
                'Search config'                        => 'Search',
                'S2_SEARCH_QUICK'                      => 'Quick search',
                'S2_SEARCH_QUICK_help'                 => 'Show suggestions based on the search over titles while typing a search query.',
                'S2_SEARCH_RECOMMENDATIONS_LIMIT'      => 'Recommendations size',
                'S2_SEARCH_RECOMMENDATIONS_LIMIT_help' => 'Maximum number of recommendations. Set 0 to disable recommendations.',
                'Search index'                         => 'Search index',
                'Search current'                       => 'The index is current and updates automatically.',
                'Search repairing'                     => 'The index is repaired automatically after HTTP responses.',
                'Search updating'                      => '{{ pending }} change is updating automatically.|{{ pending }} changes are updating automatically.',
                'Search repair required'               => 'The index differs from published content and automatic repair could not be scheduled.',
                'Indexed documents'                    => 'Documents: {{ indexed }} of {{ expected }}',
                'Index storage'                        => 'Technical: {{ rows }} rows, {{ size }}',
                'Repair index'                         => 'Repair index',
                'Repair index title'                   => 'Queue a complete index reconciliation. HTTP shutdown phases resume it after this page closes or a transient failure occurs.',
                'Repair scheduled'                     => 'repair will continue automatically',
                'Repair scheduling failed'             => 'unable to schedule repair',
            ],
            default => [],
        };
    }
}
