<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

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
                'Blog config'           => 'Блог',
                'S2_BLOG_TITLE'         => 'Название блога',
                'S2_BLOG_TITLE_help'    => 'Выводится в теге &lt;title&gt;, доступно в шаблонах.',
                'Posts num'             => '{{ posts }} пост|{{ posts }} поста|{{ posts }} постов',
                'Blog new comments'     => 'Непроверенные комментарии в блоге',

                // Tags
                'Important tag'         => 'Важное',
                'Important tag info'    => 'Важные теги входят в навигационные ссылки блога',
                'Used in posts'         => '# постов',
                'Used in posts info'    => 'Количество постов, использующих этот тег, и ссылки на них.',

                'Label'      => 'Метка',
                'Label help' => 'К этой записи будут добавлены ссылки «см. также» на другие записи с такой же меткой.',
                'Display date'      => 'Дата для показа',
                'Display date help' => 'Необязательно. Показывается читателям вместо внутренней даты и времени, например «лето 1977 года». Внутренняя дата по-прежнему используется для сортировки, архива, поиска и RSS.',

                'Posts'         => 'Посты',
                'New post'      => 'Новый пост',
                'Blog comments' => 'Комменты в блоге',
                'Text'          => 'Текст',
            ],
            'en' => [
                'Blog config'           => 'Blog',
                'S2_BLOG_TITLE'         => 'Blog title',
                'S2_BLOG_TITLE_help'    => 'Used in &lt;title&gt; tag, available in templates.',
                'Posts num'             => '{{ posts }} post|{{ posts }} posts',
                'Blog new comments'     => 'Unverified comments in the blog',

                // Tags
                'Important tag'         => 'Important',
                'Important tag info'    => 'Important tags are used in the blog navigation menu',
                'Used in posts'         => 'Used in posts',
                'Used in posts info'    => 'The number of posts using this tag and links to them.',

                'Label'      => 'Label',
                'Label help' => '“See also” links (to the posts that have the same label) will be appended to this post.',
                'Display date'      => 'Display date',
                'Display date help' => 'Optional. Shown to readers instead of the internal date and time, for example “summer 1977”. The internal date is still used for sorting, archives, search, and RSS.',

                'Posts'         => 'Posts',
                'New post'      => 'New post',
                'Blog comments' => 'Blog comments',
                'Text'          => 'Text',
            ],
            default => [],
        };
    }
}
