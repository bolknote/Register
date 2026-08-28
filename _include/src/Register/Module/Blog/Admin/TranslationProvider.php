<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

use Register\Admin\TranslationProviderInterface;

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
                'REGISTER_BLOG_TITLE'         => 'Название блога',
                'REGISTER_BLOG_TITLE_help'    => 'Выводится в теге &lt;title&gt;, доступно в шаблонах.',
                'REGISTER_SITE_TAGLINE'       => 'Подпись сайта',
                'REGISTER_SITE_TAGLINE_help'  => 'Короткое описание под названием сайта.',
                'Posts num'             => '{{ posts }} пост|{{ posts }} поста|{{ posts }} постов',
                'Blog new comments'     => 'Непроверенные комментарии к постам',

                // Tags
                'Important tag'         => 'Важное',
                'Important tag info'    => 'Важные теги входят в навигационные ссылки блога',
                'Used in posts'         => 'В постах',
                'Used in posts info'    => 'Количество постов с этим тегом и ссылки на них.',

                'Label'      => 'Метка',
                'Label help' => 'К этому посту будут добавлены ссылки «см. также» на посты с такой же меткой.',
                'Display date'      => 'Дата для показа',
                'Display date help' => 'Необязательно. Показывается читателям вместо внутренней даты и времени, например «лето 1977 года». Внутренняя дата по-прежнему используется для сортировки, архива, поиска и RSS.',

                'Posts'         => 'Посты',
                'New post'      => 'Новый пост',
                'Blog comments' => 'Комментарии к постам',
                'Text'          => 'Текст',
            ],
            'en' => [
                'Blog config'           => 'Blog',
                'REGISTER_BLOG_TITLE'         => 'Blog title',
                'REGISTER_BLOG_TITLE_help'    => 'Used in &lt;title&gt; tag, available in templates.',
                'REGISTER_SITE_TAGLINE'       => 'Site tagline',
                'REGISTER_SITE_TAGLINE_help'  => 'A short description displayed below the site title.',
                'Posts num'             => '{{ posts }} post|{{ posts }} posts',
                'Blog new comments'     => 'Unverified post comments',

                // Tags
                'Important tag'         => 'Important',
                'Important tag info'    => 'Important tags are used in the blog navigation menu',
                'Used in posts'         => 'In posts',
                'Used in posts info'    => 'The number of posts using this tag and links to them.',

                'Label'      => 'Label',
                'Label help' => '“See also” links (to the posts that have the same label) will be appended to this post.',
                'Display date'      => 'Display date',
                'Display date help' => 'Optional. Shown to readers instead of the internal date and time, for example “summer 1977”. The internal date is still used for sorting, archives, search, and RSS.',

                'Posts'         => 'Posts',
                'New post'      => 'New post',
                'Blog comments' => 'Post comments',
                'Text'          => 'Text',
            ],
            default => [],
        };
    }
}
