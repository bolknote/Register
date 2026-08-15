(() => {
    'use strict';

    const body = document.body;
    const header = document.getElementById('header');
    const container = document.getElementById('container');

    if (!body || !header || !container || container.querySelector('.system-window-title')) {
        return;
    }

    const isRussian = document.documentElement.lang.toLowerCase().startsWith('ru');
    const labels = isRussian
        ? {
            articles: 'Публикации',
            document: 'Документ',
            finder: 'Finder',
            item: ['объект', 'объекта', 'объектов'],
            readMe: 'Прочти меня',
            systemDisk: 'Системный диск',
            words: 'слов',
        }
        : {
            articles: 'Articles',
            document: 'Document',
            finder: 'Finder',
            item: ['item', 'items', 'items'],
            readMe: 'Read Me',
            systemDisk: 'System Disk',
            words: 'words',
        };

    const makeElement = (tagName, className, text = '') => {
        const element = document.createElement(tagName);
        element.className = className;
        element.textContent = text;
        return element;
    };

    const siteTitle = header.querySelector('.site-title')?.textContent.trim() || document.title;
    const contentTitle = document.querySelector('#content > h1, #content > .post.head a')?.textContent.trim();
    const isIndex = body.classList.contains('blog_main') || body.classList.contains('mainpage');
    const windowTitleText = isIndex
        ? `${siteTitle} — ${labels.articles}`
        : (contentTitle || document.title || labels.document);

    const titleBar = makeElement('div', 'system-window-title');
    titleBar.setAttribute('aria-hidden', 'true');
    titleBar.append(
        makeElement('span', 'system-window-close'),
        makeElement('strong', 'system-window-name', windowTitleText.slice(0, 92)),
        makeElement('span', 'system-window-zoom'),
    );
    container.prepend(titleBar);

    const postsCount = container.querySelectorAll('#content > .post.head').length;
    const text = container.querySelector('#content')?.textContent.trim() || '';
    const wordsCount = text === '' ? 0 : text.split(/\s+/u).length;
    const pluralIndex = (number) => {
        if (!isRussian) {
            return number === 1 ? 0 : 1;
        }

        const lastTwo = number % 100;
        const last = number % 10;
        if (lastTwo >= 11 && lastTwo <= 14) {
            return 2;
        }
        if (last === 1) {
            return 0;
        }
        if (last >= 2 && last <= 4) {
            return 1;
        }
        return 2;
    };

    const statusBar = makeElement('div', 'system-window-status');
    statusBar.setAttribute('aria-hidden', 'true');
    const statusText = postsCount > 1
        ? `${postsCount} ${labels.item[pluralIndex(postsCount)]}`
        : `${wordsCount} ${labels.words}`;
    statusBar.append(
        makeElement('span', 'system-window-count', statusText),
        makeElement('span', 'system-window-version', 'System 1'),
    );
    container.append(statusBar);

    const desktop = makeElement('div', 'system-desktop-icons');
    desktop.setAttribute('aria-hidden', 'true');
    [
        ['system-desktop-finder', labels.systemDisk],
        ['system-desktop-folder', labels.articles],
        ['system-desktop-document', labels.readMe],
    ].forEach(([iconClass, label]) => {
        const icon = makeElement('span', `system-desktop-icon ${iconClass}`);
        icon.append(
            makeElement('span', 'system-desktop-glyph'),
            makeElement('span', 'system-desktop-label', label),
        );
        desktop.append(icon);
    });
    body.append(desktop);

    const clockFormatter = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
    const updateClock = () => {
        header.dataset.systemClock = clockFormatter.format(new Date());
    };
    updateClock();
    window.setInterval(updateClock, 60_000);

    document.documentElement.classList.add('system-1-ready');
})();
