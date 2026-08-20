<?php

declare(strict_types = 1);

return [
    'locale'                 => 'ru',

    // Error messages
    'Error encountered'      => 'Произошла ошибка',
    'DB repeat items'        => 'Ошибка в базе данных: наличие повторяющихся элементов.',
    'Error no template'      => 'Ни у одного из разделов шаблон не найден.<br /><br />Вы должны задать какой-либо шаблон хотя бы у одного элемента из перечисленных ниже:<br />%s',
    'Error no template flat' => 'Шаблон страницы не найден.<br /><br />Вы должны у этой страницы задать какой-либо шаблон.',
    'Template not found'     => 'Отсутствует файл шаблона <strong>%s</strong>. Если эта ошибка будет повторяться, попробуйте переустановить Register.',
    'Error 404'              => 'Ошибка 404',
    'Error 404 text'         => 'Эта страница никогда не&nbsp;существовала или&nbsp;была удалена. Перейдите на&nbsp;<a href="%1$s">главную</a> и&nbsp;найдите нужную страницу самостоятельно, либо&nbsp;напишите автору сайта.',

    // Page content
    'Skip to content'        => 'Перейти к содержанию',
    'Breadcrumbs'            => 'Навигационная цепочка',
    'In this section'        => 'В этом разделе',
    'Read in this section'   => 'Читайте в этом разделе',
    'More in this section'   => 'Еще в разделе <nobr>«%s»</nobr>',
    'Subsections'            => 'Подразделы',
    'Tags'                   => 'Ключевые слова',
    'With this tag'          => 'По теме «%s»',
    'Read next'              => 'Читайте также',
    'Comments'               => 'Комментарии',
    'Copyright 1'            => '© %1$s, %2$s',
    'Copyright 2'            => '© %1$s, %2$s–%3$s',
    'Powered by'             => 'Движок — %s',
    'Engine credit'          => 'Движок — %s',
    'Administration login'   => 'Войти в панель управления',
    'Performance info'       => 'Страница собрана за %1$s мс, запросов к базе: %2$d',
    'Last comments'          => 'Последние комментарии на&nbsp;сайте',
    'Last discussions'       => 'Обсуждаемое на&nbsp;сайте',
    'Here'                   => '← сюда',
    'There'                  => 'туда →',

    'Favorite'               => 'Избранное',

    // RSS
    'RSS description'        => '%s. Последние страницы.',
    'RSS link title'         => 'Последние страницы сайта',

    // Comments
    'Wrote'                  => 'пишет:',
    'Comment info format'    => '%1$s. %2$s пишет:',
    'Post a comment'         => 'Оставьте свой комментарий',
    'Your name'              => 'Ваше имя:',
    'Your email'             => 'Электронная почта:',
    'Your comment'           => 'Комментарий:',
    'Show email label'       => 'Показывать адрес посетителям сайта',
    'Show email label title' => '',
    'Subscribe label'        => 'Подписаться на новые комментарии',
    'Subscribe label title'  => 'Комментарии других пользователей будут приходить вам по почте. Сможете отписаться, когда надоест.',
    'Comment syntax info'    => 'Выделение текста: [i]<i>курсивом</i>[/i] или [b]<b>жирным</b>[/b].<br />Цитату оформляйте так: [q = имя автора]цитата[/q] или [q]еще цитата[/q].<br />Других команд или HTML-тегов здесь нет.',
    'Reply'                  => 'Ответить',
    'Replying to'            => 'Ответ для',
    'Cancel reply'           => 'Отменить',
    'Comment moderation'     => 'Управление комментарием',
    'Edit comment'           => 'Редактировать комментарий',
    'Delete comment'         => 'Удалить комментарий',
    'Mark comment as spam'   => 'Пометить как спам',
    'Mark comment as not spam' => 'Не спам',
    'Comment deleted'        => 'Комментарий удалён',
    'Comment is spam'        => 'спам',
    'Comment is hidden'      => 'скрыт',
    'Confirm comment deletion' => 'Удалить этот комментарий?',
    'Confirm comment spam'   => 'Пометить этот комментарий как спам?',
    'Confirm comment ham'    => 'Вернуть этот комментарий из спама?',
    'Save comment changes'   => 'Сохранить',
    'Cancel comment changes' => 'Отмена',
    'Comment moderation forbidden' => 'Недостаточно прав для управления комментарием.',
    'Invalid comment moderation request' => 'Некорректная команда управления комментарием.',
    'Comment moderation token expired' => 'Защитный код устарел. Обновите страницу.',
    'Invalid edited comment' => 'Комментарий не может быть пустым или слишком длинным.',
    'Comment not found'      => 'Комментарий не найден.',
    'Site author'            => 'автор',
    'Comment permalink'      => 'Постоянная ссылка на комментарий № %number%',
    'Email privacy note'     => 'Не публикуется без вашего разрешения.',
    'Formatting help'        => 'Как оформить текст',

    'Link inventory deletion wait' => 'Нельзя удалять материалы, пока не закончилась первичная инвентаризация ссылок.',
    'Cannot delete linked content'  => 'Нельзя удалить «{{ target }}»: на материал ссылаются {{ sources }}.',
    'Link deletion more sources'    => 'и ещё {{ count }}',

    'Submit'      => 'Отправить',
    'Preview'     => 'Предпросмотр',
    'Error'       => 'Oшибка!',

    // Locale settings
    'Date format' => 'j F Y года', // See http://php.net/manual/en/function.date.php for details
    'Time format' => 'j F Y года, H:i',

    'Decimal count'       => 2,
    'Decimal point'       => ',',
    'Thousands separator' => ' ',

    'January'   => 'Январь',
    'February'  => 'Февраль',
    'March'     => 'Март',
    'April'     => 'Апрель',
    'May'       => 'Май',
    'June'      => 'Июнь',
    'July'      => 'Июль',
    'August'    => 'Август',
    'September' => 'Сентябрь',
    'October'   => 'Октябрь',
    'November'  => 'Ноябрь',
    'December'  => 'Декабрь',

    'January genitive'   => 'января',
    'February genitive'  => 'февраля',
    'March genitive'     => 'марта',
    'April genitive'     => 'апреля',
    'May genitive'       => 'мая',
    'June genitive'      => 'июня',
    'July genitive'      => 'июля',
    'August genitive'    => 'августа',
    'September genitive' => 'сентября',
    'October genitive'   => 'октября',
    'November genitive'  => 'ноября',
    'December genitive'  => 'декабря',

    'File size format' => '%1$s %2$s', // %1$s = number, %2$s = unit
    'File size units'  => ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ', 'ПБ', 'ЭБ', 'ЗБ', 'ИБ']
];
