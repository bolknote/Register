/**
 * Renders Register's privacy-conscious daily analytics on the authenticated dashboard.
 */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.register-analytics');
    if (root === null || typeof Highcharts === 'undefined') {
        return;
    }

    const endpoint = root.dataset.endpoint;
    const reportEndpoint = root.dataset.reportEndpoint;
    if (endpoint === undefined || reportEndpoint === undefined) {
        return;
    }

    const styles = getComputedStyle(root);
    const color = (name) => styles.getPropertyValue(name).trim();
    const theme = {
        accent: color('--admin-accent-color'),
        border: color('--admin-border-color'),
        secondary: color('--admin-secondary-background'),
        secondaryText: color('--admin-secondary-text-color'),
        surface: color('--admin-surface-background'),
        text: color('--admin-text-color'),
    };

    const load = async (channel) => {
        const response = await fetch(`${endpoint}&channel=${encodeURIComponent(channel)}`, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            throw new Error(`${root.dataset.requestFailed ?? 'Analytics request failed'}: HTTP ${response.status}`);
        }

        const data = await response.json();
        return data.series.map((item) => [Date.parse(`${item.day}T00:00:00Z`), item]);
    };

    const loadReport = async (report) => {
        const response = await fetch(`${reportEndpoint}&report=${encodeURIComponent(report)}`, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            throw new Error(`${root.dataset.requestFailed ?? 'Analytics request failed'}: HTTP ${response.status}`);
        }

        const payload = await response.json();
        return payload.data;
    };

    const renderTable = (id, series) => {
        const container = root.querySelector(`[data-analytics-table="${id}"]`);
        const details = container?.closest('details');
        if (container === null || details === null || details === undefined) {
            return;
        }

        const timestamps = [...new Set(series.flatMap((item) => item.data.map(([timestamp]) => timestamp)))]
            .sort((first, second) => first - second);
        if (timestamps.length === 0) {
            return;
        }

        const table = document.createElement('table');
        table.className = 'analytics-data-table';
        const caption = table.createCaption();
        caption.className = 'visually-hidden';
        caption.textContent = document.getElementById(id)?.getAttribute('aria-label') ?? '';

        const header = table.createTHead().insertRow();
        const dayHeading = document.createElement('th');
        dayHeading.scope = 'col';
        dayHeading.textContent = root.dataset.day ?? 'Day';
        header.append(dayHeading);
        series.forEach((item) => {
            const heading = document.createElement('th');
            heading.scope = 'col';
            heading.textContent = item.name;
            header.append(heading);
        });

        const values = series.map((item) => new Map(item.data));
        const body = table.createTBody();
        timestamps.forEach((timestamp) => {
            const row = body.insertRow();
            const day = document.createElement('th');
            day.scope = 'row';
            day.textContent = new Date(timestamp).toLocaleDateString(document.documentElement.lang || undefined, {timeZone: 'UTC'});
            row.append(day);
            values.forEach((valueMap) => {
                const cell = row.insertCell();
                cell.textContent = String(valueMap.get(timestamp) ?? 0);
            });
        });

        container.replaceChildren(table);
        details.hidden = false;
    };

    const labelRangeSelector = (chart) => {
        const rangeSelector = chart.rangeSelector;
        rangeSelector?.dropdown?.setAttribute(
            'aria-label',
            root.dataset.period ?? 'Analytics period'
        );
        rangeSelector?.minInput?.setAttribute(
            'aria-label',
            root.dataset.periodStart ?? 'Analytics period start'
        );
        rangeSelector?.maxInput?.setAttribute(
            'aria-label',
            root.dataset.periodEnd ?? 'Analytics period end'
        );
    };

    const draw = (id, series) => {
        renderTable(id, series);
        const chart = Highcharts.stockChart(id, {
            accessibility: {enabled: false},
            chart: {
                backgroundColor: 'transparent',
                panning: {enabled: true, type: 'x'},
                panKey: 'alt',
                zoomType: 'x',
            },
            colors: [theme.accent, theme.secondaryText],
            credits: {enabled: false},
            legend: {
                enabled: true,
                itemHoverStyle: {color: theme.accent},
                itemStyle: {color: theme.text},
            },
            navigator: {
                maskFill: Highcharts.color(theme.accent).setOpacity(0.12).get(),
                outlineColor: theme.border,
                xAxis: {labels: {style: {color: theme.secondaryText}}},
            },
            rangeSelector: {
                buttonTheme: {
                    fill: theme.surface,
                    stroke: theme.border,
                    style: {color: theme.secondaryText},
                    states: {
                        hover: {fill: theme.secondary, style: {color: theme.text}},
                        select: {fill: theme.secondary, style: {color: theme.text}},
                    },
                },
                inputStyle: {color: theme.text},
                labelStyle: {color: theme.secondaryText},
                selected: 1,
            },
            scrollbar: {
                barBackgroundColor: theme.secondary,
                barBorderColor: theme.border,
                buttonBackgroundColor: theme.surface,
                buttonBorderColor: theme.border,
                rifleColor: theme.secondaryText,
                trackBackgroundColor: theme.surface,
                trackBorderColor: theme.border,
            },
            series,
            tooltip: {
                backgroundColor: theme.surface,
                borderColor: theme.border,
                style: {color: theme.text},
            },
            xAxis: {
                gridLineColor: theme.border,
                labels: {style: {color: theme.secondaryText}},
                lineColor: theme.border,
                tickColor: theme.border,
            },
            yAxis: {
                gridLineColor: theme.border,
                labels: {style: {color: theme.secondaryText}},
            },
        });
        labelRangeSelector(chart);
    };

    const sourceLabel = (row) => {
        if (row.kind === 'campaign') {
            return [row.utm_source, row.utm_medium, row.utm_campaign].filter(Boolean).join(' / ') || row.kind;
        }
        return row.referrer_host || row.kind;
    };

    const renderRanking = (name, rows) => {
        const container = root.querySelector(`[data-analytics-ranking="${name}"]`);
        if (container === null) {
            return;
        }
        if (!Array.isArray(rows) || rows.length === 0) {
            container.textContent = root.dataset.noData ?? 'No analytics data';
            return;
        }

        const table = document.createElement('table');
        table.className = 'analytics-data-table';
        const header = table.createTHead().insertRow();
        const labels = [
            name === 'pages' ? (root.dataset.page ?? 'Page') : (root.dataset.source ?? 'Source'),
            root.dataset.pageViews ?? 'Page views',
            root.dataset.sessions ?? 'Sessions',
            root.dataset.uniqueVisitors ?? 'Unique visitors',
        ];
        labels.forEach((label) => {
            const heading = document.createElement('th');
            heading.scope = 'col';
            heading.textContent = label;
            header.append(heading);
        });

        const body = table.createTBody();
        rows.forEach((item) => {
            const row = body.insertRow();
            const label = document.createElement('th');
            label.scope = 'row';
            label.textContent = name === 'pages'
                ? (item.title ? `${item.title} — ${item.path}` : item.path)
                : sourceLabel(item);
            row.append(label);
            [item.views, item.sessions, item.unique_count].forEach((value) => {
                const cell = row.insertCell();
                cell.textContent = String(value);
            });
        });
        container.replaceChildren(table);
    };

    Promise.all([
        load('page'),
        load('feed:blog'),
        loadReport('daily'),
        loadReport('pages'),
        loadReport('sources'),
    ])
        .then(([pages, blogFeed, overview, topPages, topSources]) => {
            draw('register-analytics-pages', [
                {name: root.dataset.pageViews ?? 'Page views', data: pages.map(([time, item]) => [time, item.hits])},
                {name: root.dataset.uniqueVisitors ?? 'Unique visitors', data: pages.map(([time, item]) => [time, item.unique_count])},
            ]);
            const daily = overview.map((item) => [Date.parse(`${item.day}T00:00:00Z`), item]);
            draw('register-analytics-sessions', [
                {name: root.dataset.sessions ?? 'Sessions', data: daily.map(([time, item]) => [time, item.sessions])},
                {name: root.dataset.bounces ?? 'Bounces', data: daily.map(([time, item]) => [time, item.bounces])},
            ]);
            draw('register-analytics-feeds', [
                {name: root.dataset.blogFeedReaders ?? 'Blog feed readers', data: blogFeed.map(([time, item]) => [time, item.unique_count])},
            ]);
            renderRanking('pages', topPages);
            renderRanking('sources', topSources);
        })
        .catch((error) => {
            const message = document.createElement('p');
            message.className = 'technical-data';
            message.textContent = error instanceof Error ? error.message : (root.dataset.loadFailed ?? 'Unable to load analytics.');
            root.append(message);
        });
});
