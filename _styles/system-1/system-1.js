(() => {
    'use strict';

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

    const ensureDesktopIcons = (body) => {
        if (body.querySelector(':scope > .system-desktop-icons')) {
            return;
        }

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
    };

    const updateWindowChrome = () => {
        const body = document.body;
        const header = document.getElementById('header');
        const container = document.getElementById('container');
        const content = container?.querySelector('#content');

        if (!body || !header || !container || !content) {
            return;
        }

        const siteTitle = header.querySelector('.site-title')?.textContent.trim() || document.title;
        const contentTitle = document.querySelector(
            '#content > h1, #content > .post.head a, #content .post-card > .post.head a',
        )?.textContent.trim();
        const isIndex = body.classList.contains('blog_main') || body.classList.contains('mainpage');
        const windowTitleText = isIndex
            ? `${siteTitle} — ${labels.articles}`
            : (contentTitle || document.title || labels.document);

        let titleBar = container.querySelector(':scope > .system-window-title');
        if (!titleBar) {
            titleBar = makeElement('div', 'system-window-title');
            titleBar.setAttribute('aria-hidden', 'true');
            titleBar.append(
                makeElement('span', 'system-window-close'),
                makeElement('strong', 'system-window-name'),
                makeElement('span', 'system-window-zoom'),
            );
            container.prepend(titleBar);
        }
        titleBar.querySelector('.system-window-name').textContent = windowTitleText.slice(0, 92);

        const postsCount = content.querySelectorAll(':scope > .post.head, .post-card > .post.head').length;
        const text = content.textContent.trim();
        const wordsCount = text === '' ? 0 : text.split(/\s+/u).length;
        const statusText = isIndex
            ? `${postsCount} ${labels.item[pluralIndex(postsCount)]}`
            : `${wordsCount} ${labels.words}`;

        let statusBar = container.querySelector(':scope > .system-window-status');
        if (!statusBar) {
            statusBar = makeElement('div', 'system-window-status');
            statusBar.setAttribute('aria-hidden', 'true');
            statusBar.append(
                makeElement('span', 'system-window-count'),
                makeElement('span', 'system-window-version', 'System 1'),
            );
            container.append(statusBar);
        }
        statusBar.querySelector('.system-window-count').textContent = statusText;

        ensureDesktopIcons(body);
        document.documentElement.classList.add('system-1-ready');
    };

    const clockFormatter = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
    const updateClock = () => {
        const header = document.getElementById('header');
        if (header) {
            header.dataset.systemClock = clockFormatter.format(new Date());
        }
    };
    updateWindowChrome();
    updateClock();
    window.setInterval(updateClock, 60_000);

    document.addEventListener('register:navigation-updated', () => {
        updateWindowChrome();
        updateClock();
    });
    document.addEventListener('register:fragment-updated', () => {
        window.requestAnimationFrame(updateWindowChrome);
    });
})();
