(function () {
    'use strict';

    if (
        typeof window.Intl === 'undefined'
        || typeof window.Intl.DateTimeFormat === 'undefined'
    ) {
        return;
    }

    document.documentElement.classList.add('local-time-pending');

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

    function localizeTimes() {
        if (document.readyState === 'loading') {
            return;
        }

        document.querySelectorAll('time[data-local-time]').forEach(localizeTime);
        document.documentElement.classList.remove('local-time-pending');
        document.removeEventListener('readystatechange', localizeTimes);
    }

    document.addEventListener('readystatechange', localizeTimes);
    localizeTimes();
}());
