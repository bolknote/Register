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
                'Search updating'                      => 'Автоматически обрабатывается {{ pending }} изменение.|Автоматически обрабатываются {{ pending }} изменения.|Автоматически обрабатываются {{ pending }} изменений.',
                'Search repair required'               => 'Индекс расходится с опубликованными материалами; рекомендуется восстановление.',
                'Indexed documents'                    => 'Материалы: {{ indexed }} из {{ expected }}',
                'Index storage'                        => 'Технически: {{ rows }} строк, {{ size }}',
                'Repair index'                         => 'Восстановить индекс',
                'Repair index title'                   => 'Полностью сверить и перестроить индекс. Нормальные изменения попадают в него автоматически.',
            ],
            'en' => [
                'Search config'                        => 'Search',
                'S2_SEARCH_QUICK'                      => 'Quick search',
                'S2_SEARCH_QUICK_help'                 => 'Show suggestions based on the search over titles while typing a search query.',
                'S2_SEARCH_RECOMMENDATIONS_LIMIT'      => 'Recommendations size',
                'S2_SEARCH_RECOMMENDATIONS_LIMIT_help' => 'Maximum number of recommendations. Set 0 to disable recommendations.',
                'Search index'                         => 'Search index',
                'Search current'                       => 'The index is current and updates automatically.',
                'Search updating'                      => '{{ pending }} change is updating automatically.|{{ pending }} changes are updating automatically.',
                'Search repair required'               => 'The index differs from published content; repair is recommended.',
                'Indexed documents'                    => 'Documents: {{ indexed }} of {{ expected }}',
                'Index storage'                        => 'Technical: {{ rows }} rows, {{ size }}',
                'Repair index'                         => 'Repair index',
                'Repair index title'                   => 'Verify and rebuild the complete index. Normal changes are indexed automatically.',
            ],
            default => [],
        };
    }
}
