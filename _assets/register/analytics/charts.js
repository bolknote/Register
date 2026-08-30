/**
 * Renders the authenticated analytics dashboard without exposing empty panels.
 */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.register-analytics');
    if (root === null) {
        return;
    }

    const loading = root.querySelector('[data-analytics-loading]');
    const emptyState = root.querySelector('[data-analytics-empty]');
    const errorState = root.querySelector('[data-analytics-error]');
    const rankingGrid = root.querySelector('.analytics-ranking-grid');
    const hideLoading = () => {
        if (loading !== null) {
            loading.hidden = true;
        }
    };
    const showError = (message) => {
        hideLoading();
        if (errorState !== null) {
            errorState.textContent = message;
            errorState.hidden = false;
        }
    };
    const clearError = () => {
        if (errorState !== null) {
            errorState.textContent = '';
            errorState.hidden = true;
        }
    };

    if (typeof Highcharts === 'undefined') {
        showError(root.dataset.loadFailed ?? 'Unable to load analytics.');
        return;
    }

    const endpoint = root.dataset.endpoint;
    const reportEndpoint = root.dataset.reportEndpoint;
    if (endpoint === undefined || reportEndpoint === undefined) {
        showError(root.dataset.loadFailed ?? 'Unable to load analytics.');
        return;
    }

    const locale = document.documentElement.lang || navigator.language || 'en';
    const integerFormatter = new Intl.NumberFormat(locale, {maximumFractionDigits: 0});
    const decimalFormatter = new Intl.NumberFormat(locale, {maximumFractionDigits: 1});
    const shortDateFormatter = new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
    const longDateFormatter = new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
    root.querySelectorAll('.analytics-summary-value').forEach((element) => {
        const source = element.textContent.trim();
        const percent = source.endsWith('%');
        const value = Number(source.replace('%', '').replace(',', '.'));
        if (Number.isFinite(value)) {
            element.textContent = `${percent ? decimalFormatter.format(value) : integerFormatter.format(value)}${percent ? '%' : ''}`;
        }
    });

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
    const oneDay = 24 * 60 * 60 * 1000;
    const chartDefinitions = new Map();
    const charts = new Map();
    let earliestTimestamp = Date.parse(`${root.dataset.today ?? ''}T00:00:00Z`);
    let rankingRequest = 0;

    const load = async (channel) => {
        const response = await fetch(`${endpoint}&channel=${encodeURIComponent(channel)}`, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            throw new Error(`${root.dataset.requestFailed ?? 'Analytics request failed'}: HTTP ${response.status}`);
        }

        const payload = await response.json();
        if (payload?.success !== true || !Array.isArray(payload.series)) {
            throw new Error(root.dataset.loadFailed ?? 'Unable to load analytics.');
        }

        return payload.series.flatMap((item) => {
            const timestamp = typeof item?.day === 'string'
                ? Date.parse(`${item.day}T00:00:00Z`)
                : Number.NaN;
            return Number.isFinite(timestamp) ? [[timestamp, item]] : [];
        });
    };

    const loadReport = async (report, fromDay, toDay) => {
        const url = `${reportEndpoint}&report=${encodeURIComponent(report)}`
            + `&from=${encodeURIComponent(fromDay)}&to=${encodeURIComponent(toDay)}`;
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            throw new Error(`${root.dataset.requestFailed ?? 'Analytics request failed'}: HTTP ${response.status}`);
        }

        const payload = await response.json();
        if (payload?.success !== true || !Array.isArray(payload.data)) {
            throw new Error(root.dataset.loadFailed ?? 'Unable to load analytics.');
        }
        return payload.data;
    };

    const isoDay = (timestamp) => new Date(timestamp).toISOString().slice(0, 10);

    const selectedRange = () => root.querySelector('[data-analytics-range-days][aria-pressed="true"]')
        ?.dataset.analyticsRangeDays ?? '30';

    const rangeBounds = (range) => {
        const today = Date.parse(`${root.dataset.today ?? ''}T00:00:00Z`);
        const toTimestamp = Number.isFinite(today) ? today : Date.now();
        const days = range === 'all' ? null : Number(range);
        const fromTimestamp = days === null || !Number.isFinite(days)
            ? earliestTimestamp
            : toTimestamp - Math.max(0, days - 1) * oneDay;
        return {
            fromDay: isoDay(Math.min(fromTimestamp, toTimestamp)),
            fromTimestamp: Math.min(fromTimestamp, toTimestamp),
            toDay: isoDay(toTimestamp),
            toTimestamp,
        };
    };

    const filterSeries = (series, bounds) => series.map((item) => ({
        ...item,
        data: item.data.filter(([timestamp]) => (
            timestamp >= bounds.fromTimestamp && timestamp <= bounds.toTimestamp
        )),
    }));

    const hasValues = (series) => series.some((item) => item.data.some((point) => (
        Number.isFinite(point[1]) && point[1] > 0
    )));

    const renderTable = (id, series) => {
        const container = root.querySelector(`[data-analytics-table="${id}"]`);
        const details = container?.closest('details');
        if (container === null || details === null || details === undefined) {
            return;
        }

        const timestamps = [...new Set(series.flatMap((item) => item.data.map(([timestamp]) => timestamp)))]
            .sort((first, second) => first - second);
        if (timestamps.length === 0) {
            container.replaceChildren();
            details.hidden = true;
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
            day.textContent = longDateFormatter.format(new Date(timestamp));
            row.append(day);
            values.forEach((valueMap) => {
                const cell = row.insertCell();
                cell.textContent = integerFormatter.format(valueMap.get(timestamp) ?? 0);
            });
        });

        container.replaceChildren(table);
        details.hidden = false;
    };

    const chartOptions = (series) => ({
        accessibility: {enabled: false},
        chart: {
            animation: false,
            backgroundColor: 'transparent',
            spacing: [6, 8, 4, 4],
            style: {fontFamily: 'inherit'},
        },
        colors: [theme.accent, theme.secondaryText],
        credits: {enabled: false},
        legend: {
            align: 'left',
            enabled: series.length > 1,
            itemHoverStyle: {color: theme.accent},
            itemStyle: {color: theme.text, fontWeight: '600'},
            symbolRadius: 0,
            verticalAlign: 'top',
        },
        plotOptions: {
            series: {
                animation: false,
                lineWidth: 2,
                marker: {enabled: true, radius: 2},
                states: {hover: {lineWidthPlus: 0}},
            },
        },
        series: series.map((item, index) => ({
            color: index === 0 ? theme.accent : theme.secondaryText,
            data: item.data,
            fillColor: index === 0 ? {
                linearGradient: {x1: 0, x2: 0, y1: 0, y2: 1},
                stops: [
                    [0, Highcharts.color(theme.accent).setOpacity(0.25).get()],
                    [1, Highcharts.color(theme.accent).setOpacity(0.01).get()],
                ],
            } : undefined,
            name: item.name,
            type: index === 0 ? 'areaspline' : 'spline',
        })),
        time: {useUTC: true},
        title: {text: null},
        tooltip: {
            backgroundColor: theme.surface,
            borderColor: theme.border,
            borderRadius: 6,
            shared: true,
            style: {color: theme.text},
            formatter() {
                const points = this.points ?? [];
                const lines = points.map((point) => (
                    `<span style="color:${point.color}">●</span> ${point.series.name}: `
                    + `<b>${integerFormatter.format(point.y ?? 0)}</b>`
                ));
                return [`<b>${longDateFormatter.format(new Date(this.x))}</b>`, ...lines].join('<br>');
            },
        },
        xAxis: {
            gridLineColor: theme.border,
            lineColor: theme.border,
            tickColor: theme.border,
            tickLength: 0,
            tickPixelInterval: 105,
            type: 'datetime',
            labels: {
                formatter() {
                    return shortDateFormatter.format(new Date(this.value));
                },
                style: {color: theme.secondaryText},
            },
        },
        yAxis: {
            allowDecimals: false,
            gridLineColor: theme.border,
            min: 0,
            title: {text: null},
            labels: {
                formatter() {
                    return integerFormatter.format(this.value);
                },
                style: {color: theme.secondaryText},
            },
        },
    });

    const renderChart = (name, definition, bounds) => {
        const panel = root.querySelector(`[data-analytics-panel="${name}"]`);
        if (panel === null) {
            return false;
        }

        const series = filterSeries(definition.series, bounds);
        if (!hasValues(series)) {
            panel.hidden = true;
            return false;
        }

        panel.hidden = false;
        renderTable(definition.id, series);
        const chart = charts.get(name);
        if (chart === undefined) {
            charts.set(name, Highcharts.chart(definition.id, chartOptions(series)));
        } else {
            series.forEach((item, index) => {
                chart.series[index]?.setData(item.data, false);
            });
            chart.redraw();
            chart.reflow();
        }
        return true;
    };

    const sourceKinds = {
        campaign: root.dataset.sourceCampaign,
        direct: root.dataset.sourceDirect,
        internal: root.dataset.sourceInternal,
        referral: root.dataset.sourceReferral,
        search: root.dataset.sourceSearch,
        social: root.dataset.sourceSocial,
    };

    const sourceLabel = (row) => {
        if (row.kind === 'campaign') {
            return [row.utm_source, row.utm_medium, row.utm_campaign].filter(Boolean).join(' / ')
                || sourceKinds.campaign
                || row.kind;
        }
        return row.referrer_host || sourceKinds[row.kind] || row.kind;
    };

    const renderRanking = (name, rows) => {
        const panel = root.querySelector(`[data-analytics-ranking-panel="${name}"]`);
        const container = root.querySelector(`[data-analytics-ranking="${name}"]`);
        if (panel === null || container === null || !Array.isArray(rows) || rows.length === 0) {
            if (panel !== null) {
                panel.hidden = true;
            }
            container?.replaceChildren();
            return false;
        }

        const table = document.createElement('table');
        table.className = 'analytics-data-table';
        const caption = table.createCaption();
        caption.className = 'visually-hidden';
        caption.textContent = panel.querySelector('h3')?.textContent ?? '';
        const header = table.createTHead().insertRow();
        const labels = [
            name === 'pages' ? (root.dataset.page ?? 'Page') : (root.dataset.source ?? 'Source'),
            root.dataset.pageViews ?? 'Page views',
            root.dataset.sessions ?? 'Sessions',
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
            if (name === 'pages' && typeof item.path === 'string' && item.path.startsWith('/')) {
                const link = document.createElement('a');
                link.href = item.path;
                link.textContent = item.title ? `${item.title} — ${item.path}` : item.path;
                label.append(link);
            } else {
                label.textContent = name === 'pages'
                    ? (item.title ? `${item.title} — ${item.path}` : item.path)
                    : sourceLabel(item);
            }
            row.append(label);
            [item.views, item.sessions].forEach((value) => {
                const cell = row.insertCell();
                cell.textContent = integerFormatter.format(Number(value) || 0);
            });
        });
        container.replaceChildren(table);
        panel.hidden = false;
        return true;
    };

    const updateEmptyState = () => {
        const visible = root.querySelectorAll(
            '[data-analytics-panel]:not([hidden]), [data-analytics-ranking-panel]:not([hidden])'
        ).length > 0;
        if (emptyState !== null) {
            emptyState.hidden = visible;
        }
    };

    const refreshRankings = async (bounds) => {
        const request = ++rankingRequest;
        const [pages, sources] = await Promise.all([
            loadReport('pages', bounds.fromDay, bounds.toDay),
            loadReport('sources', bounds.fromDay, bounds.toDay),
        ]);
        if (request !== rankingRequest) {
            return;
        }

        const pagesVisible = renderRanking('pages', pages);
        const sourcesVisible = renderRanking('sources', sources);
        const visible = pagesVisible || sourcesVisible;
        if (rankingGrid !== null) {
            rankingGrid.hidden = !visible;
        }
    };

    const applyRange = async (range) => {
        root.querySelectorAll('[data-analytics-range-days]').forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset.analyticsRangeDays === range ? 'true' : 'false');
        });
        const bounds = rangeBounds(range);
        chartDefinitions.forEach((definition, name) => renderChart(name, definition, bounds));
        await refreshRankings(bounds);
        clearError();
        updateEmptyState();
    };

    root.querySelectorAll('[data-analytics-range-days]').forEach((button) => {
        button.addEventListener('click', () => {
            void applyRange(button.dataset.analyticsRangeDays ?? '30').catch((error) => {
                showError(error instanceof Error ? error.message : (root.dataset.loadFailed ?? 'Unable to load analytics.'));
            });
        });
    });

    Promise.all([
        load('page'),
        load('feed:blog'),
        loadReport('daily', root.dataset.today ?? '', root.dataset.today ?? ''),
    ])
        .then(async ([pages, blogFeed, overview]) => {
            const daily = overview.flatMap((item) => {
                const timestamp = typeof item?.day === 'string'
                    ? Date.parse(`${item.day}T00:00:00Z`)
                    : Number.NaN;
                return Number.isFinite(timestamp) ? [[timestamp, item]] : [];
            });
            const timestamps = [...pages, ...blogFeed, ...daily].map(([timestamp]) => timestamp);
            if (timestamps.length > 0) {
                earliestTimestamp = Math.min(...timestamps);
            }

            chartDefinitions.set('pages', {
                id: 'register-analytics-pages',
                series: [
                    {name: root.dataset.pageViews ?? 'Page views', data: pages.map(([time, item]) => [time, item.hits])},
                    {name: root.dataset.uniqueVisitors ?? 'Unique visitors', data: pages.map(([time, item]) => [time, item.unique_count])},
                ],
            });
            chartDefinitions.set('sessions', {
                id: 'register-analytics-sessions',
                series: [
                    {name: root.dataset.sessions ?? 'Sessions', data: daily.map(([time, item]) => [time, item.sessions])},
                    {name: root.dataset.bounces ?? 'Bounces', data: daily.map(([time, item]) => [time, item.bounces])},
                ],
            });
            chartDefinitions.set('feeds', {
                id: 'register-analytics-feeds',
                series: [
                    {name: root.dataset.blogFeedReaders ?? 'Blog feed readers', data: blogFeed.map(([time, item]) => [time, item.unique_count])},
                ],
            });

            await applyRange(selectedRange());
            hideLoading();
        })
        .catch((error) => {
            showError(error instanceof Error ? error.message : (root.dataset.loadFailed ?? 'Unable to load analytics.'));
        });
});
