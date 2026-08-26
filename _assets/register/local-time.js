(function () {
    'use strict';

    if (
        typeof window.Intl === 'undefined'
        || typeof window.Intl.DateTimeFormat === 'undefined'
    ) {
        return;
    }

    document.documentElement.classList.add('local-time-pending');
    var initialObserver = null;

    function formatLongDateTime(date, locale) {
        var dateText = new Intl.DateTimeFormat(locale, {dateStyle: 'long'}).format(date);
        var timeText = new Intl.DateTimeFormat(locale, {timeStyle: 'short'}).format(date);

        if (locale && locale.toLowerCase().indexOf('ru') === 0) {
            dateText = dateText.replace(/\s+г\.$/, ' года');
            return dateText + ', ' + timeText;
        }

        return dateText + '. ' + timeText;
    }

    function formatTechnicalDateTime(date, locale) {
        var formatter = new Intl.DateTimeFormat(locale, {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            timeZoneName: 'short'
        });
        var parts = {};

        formatter.formatToParts(date).forEach(function (part) {
            if (part.type !== 'literal') {
                parts[part.type] = part.value;
            }
        });

        if (parts.year && parts.month && parts.day && parts.hour && parts.minute) {
            return parts.year + '-' + parts.month + '-' + parts.day
                + ' ' + parts.hour + ':' + parts.minute
                + (parts.timeZoneName ? ' ' + parts.timeZoneName : '');
        }

        return formatter.format(date);
    }

    function localizeTime(element) {
        if (element.dataset.localTimeReady === '1') {
            return;
        }

        var date = new Date(element.getAttribute('datetime') || '');
        if (Number.isNaN(date.getTime())) {
            return;
        }

        var locale = element.getAttribute('data-locale') || document.documentElement.lang || undefined;

        try {
            element.textContent = element.dataset.localTime === 'datetime'
                ? formatLongDateTime(date, locale)
                : formatTechnicalDateTime(date, locale);
            element.dataset.localTimeReady = '1';
        } catch (error) {
            // Keep the explicit UTC server-rendered fallback if Intl rejects the locale or options.
        }
    }

    function onReadyStateChange() {
        localizeTimes(document);
    }

    function onInitialMutations(mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    localizeTimes(node);
                }
            });
        });
    }

    function localizeTimes(root) {
        root = root || document;
        if (root === document && document.readyState === 'loading') {
            return;
        }

        if (root instanceof Element && root.matches('time[data-local-time]')) {
            localizeTime(root);
        }
        root.querySelectorAll('time[data-local-time]').forEach(localizeTime);
        if (root === document) {
            if (initialObserver !== null) {
                initialObserver.disconnect();
                initialObserver = null;
            }
            document.documentElement.classList.remove('local-time-pending');
            document.removeEventListener('readystatechange', onReadyStateChange);
        }
    }

    window.RegisterLocalTime = Object.freeze({enhance: localizeTimes});
    if (document.readyState === 'loading' && typeof window.MutationObserver !== 'undefined') {
        initialObserver = new MutationObserver(onInitialMutations);
        initialObserver.observe(document.documentElement, {childList: true, subtree: true});
    }
    document.addEventListener('readystatechange', onReadyStateChange);
    localizeTimes(document);
}());
