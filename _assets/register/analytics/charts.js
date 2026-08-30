/**
 * Renders the authenticated content analytics dashboard without exposing empty panels.
 */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.register-analytics');
    if (root === null) {
        return;
    }

    const loading = root.querySelector('[data-analytics-loading]');
    const emptyState = root.querySelector('[data-analytics-empty]');
    const errorState = root.querySelector('[data-analytics-error]');
    const hideLoading = () => {
        if (loading !== null) loading.hidden = true;
        root.removeAttribute?.('aria-busy');
    };
    const showLoading = () => {
        root.setAttribute?.('aria-busy', 'true');
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
        showError(root.dataset.loadFailed || 'Unable to load analytics.');
        return;
    }

    const endpoint = root.dataset.endpoint;
    const reportEndpoint = root.dataset.reportEndpoint;
    if (endpoint === undefined || reportEndpoint === undefined) {
        showError(root.dataset.loadFailed || 'Unable to load analytics.');
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
    const rangeDateFormatter = new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
    const formatPercent = (value) => decimalFormatter.format(Number(value) || 0) + '%';
    const formatDuration = (seconds) => {
        const total = Math.max(0, Math.round(Number(seconds) || 0));
        if (total < 60) {
            return integerFormatter.format(total) + ' ' + (root.dataset.secondsShort || 's');
        }
        const minutes = Math.floor(total / 60);
        const remainder = total % 60;
        return integerFormatter.format(minutes) + ':' + String(remainder).padStart(2, '0');
    };

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
    const charts = new Map();
    let pageSeries = [];
    let feedSeries = [];
    let earliestTimestamp = Date.parse((root.dataset.defaultFrom || root.dataset.today || '') + 'T00:00:00Z');
    let dashboardRequest = 0;
    let currentBounds = null;

    const loadSeries = async (channel) => {
        const response = await fetch(endpoint + '&channel=' + encodeURIComponent(channel), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            throw new Error((root.dataset.requestFailed || 'Analytics request failed') + ': HTTP ' + response.status);
        }
        const payload = await response.json();
        if (payload?.success !== true || !Array.isArray(payload.series)) {
            throw new Error(root.dataset.loadFailed || 'Unable to load analytics.');
        }
        return payload.series.flatMap((item) => {
            const timestamp = typeof item?.day === 'string'
                ? Date.parse(item.day + 'T00:00:00Z')
                : Number.NaN;
            return Number.isFinite(timestamp) ? [[timestamp, item]] : [];
        });
    };

    const loadReport = async (report, fromDay, toDay) => {
        const url = reportEndpoint + '&report=' + encodeURIComponent(report)
            + '&from=' + encodeURIComponent(fromDay) + '&to=' + encodeURIComponent(toDay);
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            throw new Error((root.dataset.requestFailed || 'Analytics request failed') + ': HTTP ' + response.status);
        }
        const payload = await response.json();
        if (payload?.success !== true || payload.data === undefined) {
            throw new Error(root.dataset.loadFailed || 'Unable to load analytics.');
        }
        return payload.data;
    };

    const isoDay = (timestamp) => new Date(timestamp).toISOString().slice(0, 10);
    const selectedRange = () => root.querySelector('[data-analytics-range-days][aria-pressed="true"]')
        ?.dataset.analyticsRangeDays || '30';
    const rangeBounds = (range) => {
        const today = Date.parse((root.dataset.today || '') + 'T00:00:00Z');
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
    const updateRangeCaption = (bounds) => {
        const caption = root.querySelector('[data-analytics-range-caption]');
        if (caption === null) return;
        const from = new Date(bounds.fromTimestamp);
        const to = new Date(bounds.toTimestamp);
        caption.textContent = typeof rangeDateFormatter.formatRange === 'function'
            ? rangeDateFormatter.formatRange(from, to)
            : rangeDateFormatter.format(from) + ' — ' + rangeDateFormatter.format(to);
    };

    const hasValues = (series) => series.some((item) => item.data.some((point) => (
        Number.isFinite(point[1]) && point[1] > 0
    )));
    const filterSeries = (series, bounds) => series.map((item) => ({
        ...item,
        data: item.data.filter(([timestamp]) => (
            timestamp >= bounds.fromTimestamp && timestamp <= bounds.toTimestamp
        )),
    }));

    const renderChartTable = (id, series) => {
        const container = root.querySelector('[data-analytics-table="' + id + '"]');
        const details = container?.closest?.('details');
        if (container === null || details === null || details === undefined) return;
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
        caption.textContent = document.getElementById(id)?.getAttribute('aria-label') || '';
        const header = table.createTHead().insertRow();
        const dayHeading = document.createElement('th');
        dayHeading.scope = 'col';
        dayHeading.textContent = root.dataset.day || 'Day';
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
                cell.textContent = integerFormatter.format(valueMap.get(timestamp) || 0);
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
                const points = this.points || [];
                const lines = points.map((point) => (
                    '<span style="color:' + point.color + '">●</span> ' + point.series.name + ': '
                    + '<b>' + integerFormatter.format(point.y || 0) + '</b>'
                ));
                return ['<b>' + longDateFormatter.format(new Date(this.x)) + '</b>', ...lines].join('<br>');
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

    const renderChart = (name, id, sourceSeries, bounds) => {
        const panel = root.querySelector('[data-analytics-panel="' + name + '"]');
        if (panel === null) return false;
        const series = filterSeries(sourceSeries, bounds);
        if (!hasValues(series)) {
            panel.hidden = true;
            return false;
        }
        panel.hidden = false;
        renderChartTable(id, series);
        const chart = charts.get(name);
        if (chart === undefined) {
            charts.set(name, Highcharts.chart(id, chartOptions(series)));
        } else {
            series.forEach((item, index) => chart.series[index]?.setData(item.data, false));
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
                || sourceKinds.campaign || row.kind;
        }
        return row.referrer_host || sourceKinds[row.kind] || row.kind;
    };
    const goalLabels = {
        'comment.submit': root.dataset.goalComment,
        'file.download': root.dataset.goalDownload,
        'outbound.click': root.dataset.goalOutbound,
        'site.search': root.dataset.goalSearch,
    };
    const funnelLabels = {
        view: root.dataset.goalView,
        'content.engaged_30s': root.dataset.goalEngagedThirty,
        'content.read_75': root.dataset.goalReadSeventyFive,
        'content.read_100': root.dataset.goalReadComplete,
    };
    const deviceLabels = {
        desktop: root.dataset.deviceDesktop,
        mobile: root.dataset.deviceMobile,
        tablet: root.dataset.deviceTablet,
    };

    const createDataTable = (panel, rows, columns) => {
        const table = document.createElement('table');
        table.className = 'analytics-data-table';
        const caption = table.createCaption();
        caption.className = 'visually-hidden';
        caption.textContent = panel.querySelector?.('h3')?.textContent || '';
        const header = table.createTHead().insertRow();
        columns.forEach((column) => {
            const heading = document.createElement('th');
            heading.scope = 'col';
            heading.textContent = column.label;
            header.append(heading);
        });
        const body = table.createTBody();
        rows.forEach((item) => {
            const row = body.insertRow();
            columns.forEach((column, index) => {
                const cell = index === 0 ? document.createElement('th') : row.insertCell();
                if (index === 0) {
                    cell.scope = 'row';
                    row.append(cell);
                }
                cell.dataset.label = column.label;
                const value = column.value(item);
                if (column.link && typeof item.path === 'string' && item.path.startsWith('/')) {
                    const link = document.createElement('a');
                    link.href = item.path;
                    link.textContent = String(value);
                    cell.append(link);
                } else {
                    cell.textContent = column.format ? column.format(value) : String(value ?? '');
                }
            });
        });
        return table;
    };

    const renderRanking = (name, rows) => {
        const panel = root.querySelector('[data-analytics-ranking-panel="' + name + '"]');
        const container = root.querySelector('[data-analytics-ranking="' + name + '"]');
        if (panel === null || container === null || !Array.isArray(rows) || rows.length === 0) {
            if (panel !== null) panel.hidden = true;
            container?.replaceChildren();
            return false;
        }
        let columns;
        if (name === 'pages') {
            columns = [
                {
                    label: root.dataset.page || 'Page',
                    value: (item) => item.title ? item.title + ' — ' + item.path : item.path,
                    link: true,
                },
                {label: root.dataset.viewsShort || 'Views', value: (item) => item.views, format: integerFormatter.format},
                {label: root.dataset.activeTime || 'Time', value: (item) => item.average_engagement, format: formatDuration},
                {label: root.dataset.readSeventyFive || 'Read 75%', value: (item) => item.read_75_rate, format: formatPercent},
                {label: root.dataset.readComplete || 'Read completely', value: (item) => item.read_100_rate, format: formatPercent},
                {label: root.dataset.bounceRate || 'Bounce rate', value: (item) => item.bounce_rate, format: formatPercent},
            ];
        } else if (name === 'sources') {
            columns = [
                {label: root.dataset.source || 'Source', value: sourceLabel},
                {label: root.dataset.viewsShort || 'Views', value: (item) => item.views, format: integerFormatter.format},
                {label: root.dataset.sessions || 'Sessions', value: (item) => item.sessions, format: integerFormatter.format},
                {label: root.dataset.bounceRate || 'Bounce rate', value: (item) => item.bounce_rate, format: formatPercent},
            ];
        } else if (name === 'goals') {
            columns = [
                {label: root.dataset.goal || 'Goal', value: (item) => goalLabels[item.name] || item.name},
                {label: root.dataset.events || 'Events', value: (item) => item.events, format: integerFormatter.format},
                {label: root.dataset.readers || 'Readers', value: (item) => item.unique_count, format: integerFormatter.format},
                {label: root.dataset.conversion || 'Conversion', value: (item) => item.conversion_rate, format: formatPercent},
            ];
        } else {
            const label = container.dataset.analyticsLabel || (name === 'authors' ? root.dataset.author : root.dataset.section);
            columns = [
                {label: label || 'Name', value: (item) => item.label},
                {label: root.dataset.viewsShort || 'Views', value: (item) => item.views, format: integerFormatter.format},
                {label: root.dataset.readers || 'Readers', value: (item) => item.unique_count, format: integerFormatter.format},
                {label: root.dataset.activeTime || 'Time', value: (item) => item.average_engagement, format: formatDuration},
            ];
        }
        container.replaceChildren(createDataTable(panel, rows, columns));
        panel.hidden = false;
        return true;
    };

    const renderFunnel = (steps) => {
        const panel = root.querySelector('[data-analytics-funnel-panel]');
        const container = root.querySelector('[data-analytics-funnel]');
        if (panel === null || container === null || !Array.isArray(steps) || Number(steps[0]?.count) <= 0) {
            if (panel !== null) panel.hidden = true;
            container?.replaceChildren();
            return false;
        }
        const fragment = document.createDocumentFragment();
        steps.forEach((step) => {
            const item = document.createElement('li');
            item.className = 'analytics-funnel-step';
            const heading = document.createElement('div');
            const label = document.createElement('span');
            label.textContent = funnelLabels[step.name] || step.name;
            const value = document.createElement('strong');
            value.textContent = integerFormatter.format(Number(step.count) || 0) + ' · ' + formatPercent(step.rate);
            heading.append(label, value);
            const track = document.createElement('span');
            track.className = 'analytics-funnel-track';
            const fill = document.createElement('span');
            fill.style.width = Math.max(1, Math.min(100, Number(step.rate) || 0)) + '%';
            track.append(fill);
            item.append(heading, track);
            fragment.append(item);
        });
        container.replaceChildren(fragment);
        panel.hidden = false;
        return true;
    };

    const renderVitals = (vitals) => {
        const panel = root.querySelector('[data-analytics-vitals-panel]');
        const container = root.querySelector('[data-analytics-vitals]');
        if (panel === null || container === null || !Array.isArray(vitals) || vitals.length === 0) {
            if (panel !== null) panel.hidden = true;
            container?.replaceChildren();
            return false;
        }
        const grades = {
            good: root.dataset.vitalGood,
            needs: root.dataset.vitalNeeds,
            poor: root.dataset.vitalPoor,
        };
        const fragment = document.createDocumentFragment();
        vitals.forEach((vital) => {
            const card = document.createElement('article');
            card.className = 'analytics-vital analytics-vital-' + vital.grade;
            const name = document.createElement('strong');
            name.textContent = vital.metric;
            const value = document.createElement('span');
            value.className = 'analytics-vital-value';
            const unit = vital.unit === 'ms' ? (root.dataset.millisecondsShort || 'ms') : vital.unit;
            value.textContent = decimalFormatter.format(vital.value) + (unit ? ' ' + unit : '');
            const grade = document.createElement('span');
            grade.className = 'analytics-vital-grade';
            grade.textContent = (grades[vital.grade] || vital.grade) + ' · ' + formatPercent(vital.good_rate);
            const samples = document.createElement('small');
            samples.textContent = (root.dataset.samples || 'Samples') + ': ' + integerFormatter.format(vital.samples);
            card.append(name, value, grade, samples);
            fragment.append(card);
        });
        container.replaceChildren(fragment);
        panel.hidden = false;
        return true;
    };

    const renderTechnologyList = (name, rows) => {
        const section = root.querySelector('[data-analytics-technology="' + name + '"]');
        const container = root.querySelector('[data-analytics-ranking="' + name + '"]');
        if (section === null || container === null || !Array.isArray(rows) || rows.length === 0) {
            if (section !== null) section.hidden = true;
            container?.replaceChildren();
            return false;
        }
        const total = rows.reduce((sum, row) => sum + (Number(row.views) || 0), 0);
        const list = document.createElement('ol');
        list.className = 'analytics-breakdown';
        rows.slice(0, 6).forEach((row) => {
            const item = document.createElement('li');
            const line = document.createElement('div');
            const label = document.createElement('span');
            label.textContent = name === 'devices' ? (deviceLabels[row.label] || row.label) : row.label;
            const percent = total > 0 ? 100 * (Number(row.views) || 0) / total : 0;
            const value = document.createElement('strong');
            value.textContent = formatPercent(percent);
            line.append(label, value);
            const track = document.createElement('span');
            track.className = 'analytics-breakdown-track';
            const fill = document.createElement('span');
            fill.style.width = Math.max(1, percent) + '%';
            track.append(fill);
            item.append(line, track);
            list.append(item);
        });
        container.replaceChildren(list);
        section.hidden = false;
        return true;
    };

    const renderTechnology = (technology) => {
        const panel = root.querySelector('[data-analytics-technology-panel]');
        if (panel === null || technology === null || typeof technology !== 'object') {
            if (panel !== null) panel.hidden = true;
            return false;
        }
        const visible = [
            renderTechnologyList('devices', technology.devices),
            renderTechnologyList('browsers', technology.browsers),
            renderTechnologyList('systems', technology.systems),
        ].some(Boolean);
        panel.hidden = !visible;
        return visible;
    };

    const renderRealtime = (realtime) => {
        if (realtime === null || typeof realtime !== 'object') return;
        const visitors = root.querySelector('[data-analytics-realtime-visitors]');
        const views = root.querySelector('[data-analytics-realtime-views]');
        if (visitors !== null) visitors.textContent = integerFormatter.format(Number(realtime.active_visitors) || 0);
        if (views !== null) views.textContent = integerFormatter.format(Number(realtime.views_30m) || 0);
        const panel = root.querySelector('[data-analytics-realtime-pages-panel]');
        const container = root.querySelector('[data-analytics-realtime-pages]');
        const pages = Array.isArray(realtime.pages) ? realtime.pages : [];
        if (panel === null || container === null || pages.length === 0) {
            if (panel !== null) panel.hidden = true;
            container?.replaceChildren();
            return;
        }
        const table = createDataTable(panel, pages, [
            {
                label: root.dataset.page || 'Page',
                value: (item) => item.title ? item.title + ' — ' + item.path : item.path,
                link: true,
            },
            {label: root.dataset.readers || 'Readers', value: (item) => item.sessions, format: integerFormatter.format},
        ]);
        container.replaceChildren(table);
        panel.hidden = false;
    };

    const updateSummary = (summary, comparison) => {
        if (summary === null || typeof summary !== 'object') return;
        const formatters = {
            average_engagement: formatDuration,
            bounce_rate: formatPercent,
            pages_per_session: decimalFormatter.format,
            sessions: integerFormatter.format,
            unique_count: integerFormatter.format,
            views: integerFormatter.format,
        };
        Object.entries(formatters).forEach(([metric, formatter]) => {
            const value = root.querySelector('[data-analytics-summary="' + metric + '"]');
            if (value !== null) value.textContent = formatter(Number(summary[metric]) || 0);
            const delta = root.querySelector('[data-analytics-summary-delta="' + metric + '"]');
            if (delta === null) return;
            if (!comparison?.has_data) {
                delta.hidden = true;
                return;
            }
            const change = comparison.deltas?.[metric];
            delta.hidden = false;
            delta.classList?.remove('is-positive', 'is-negative');
            if (change === null || change === undefined) {
                delta.textContent = root.dataset.newValue || 'New';
                delta.classList?.add('is-positive');
            } else {
                const numeric = Number(change) || 0;
                delta.textContent = (numeric > 0 ? '↑ ' : (numeric < 0 ? '↓ ' : '→ ')) + formatPercent(Math.abs(numeric));
                const improvement = metric === 'bounce_rate' ? numeric < 0 : numeric > 0;
                if (numeric !== 0) delta.classList?.add(improvement ? 'is-positive' : 'is-negative');
            }
            delta.title = root.dataset.comparedWithPrevious || '';
        });
    };

    const updateExports = (bounds) => {
        root.querySelectorAll('[data-analytics-export]').forEach((link) => {
            const report = link.dataset.analyticsExport;
            link.href = reportEndpoint + '&report=' + encodeURIComponent(report)
                + '&format=csv&from=' + encodeURIComponent(bounds.fromDay)
                + '&to=' + encodeURIComponent(bounds.toDay);
        });
    };

    const updateGroups = () => {
        root.querySelectorAll('[data-analytics-group]').forEach((group) => {
            const visible = group.querySelector?.(
                '[data-analytics-panel]:not([hidden]), '
                + '[data-analytics-ranking-panel]:not([hidden]), '
                + '[data-analytics-funnel-panel]:not([hidden]), '
                + '[data-analytics-vitals-panel]:not([hidden])'
            );
            group.hidden = visible === null;
        });
    };

    const updateEmptyState = (hasTraffic) => {
        if (emptyState !== null) emptyState.hidden = hasTraffic;
    };

    const mergedTraffic = (daily) => {
        const values = new Map();
        pageSeries.forEach(([timestamp, item]) => {
            values.set(timestamp, {
                unique_count: Number(item.unique_count) || 0,
                views: Number(item.hits) || 0,
            });
        });
        daily.forEach((item) => {
            const timestamp = typeof item?.day === 'string'
                ? Date.parse(item.day + 'T00:00:00Z')
                : Number.NaN;
            if (Number.isFinite(timestamp)) {
                values.set(timestamp, {
                    unique_count: Number(item.unique_count) || 0,
                    views: Number(item.views) || 0,
                });
            }
        });
        return [...values.entries()].sort((first, second) => first[0] - second[0]);
    };

    const renderDashboard = (payload, bounds) => {
        if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
            throw new Error(root.dataset.loadFailed || 'Unable to load analytics.');
        }
        const earliest = Date.parse(String(payload.earliest_day || '') + 'T00:00:00Z');
        if (Number.isFinite(earliest)) earliestTimestamp = Math.min(earliestTimestamp, earliest);
        const daily = Array.isArray(payload.daily) ? payload.daily : [];
        const traffic = mergedTraffic(daily);
        const sessionData = daily.flatMap((item) => {
            const timestamp = typeof item?.day === 'string'
                ? Date.parse(item.day + 'T00:00:00Z')
                : Number.NaN;
            return Number.isFinite(timestamp) ? [[timestamp, item]] : [];
        });
        renderChart('pages', 'register-analytics-pages', [
            {name: root.dataset.pageViews || 'Page views', data: traffic.map(([time, item]) => [time, item.views])},
            {name: root.dataset.uniqueVisitors || 'Unique visitors', data: traffic.map(([time, item]) => [time, item.unique_count])},
        ], bounds);
        renderChart('sessions', 'register-analytics-sessions', [
            {name: root.dataset.sessions || 'Sessions', data: sessionData.map(([time, item]) => [time, item.sessions])},
            {name: root.dataset.bounces || 'Bounces', data: sessionData.map(([time, item]) => [time, item.bounces])},
        ], bounds);
        renderChart('feeds', 'register-analytics-feeds', [
            {name: root.dataset.blogFeedReaders || 'Blog feed readers', data: feedSeries.map(([time, item]) => [time, item.unique_count])},
        ], bounds);

        updateSummary(payload.summary, payload.comparison);
        renderFunnel(payload.funnel);
        renderVitals(payload.vitals);
        renderRanking('pages', payload.pages);
        renderRanking('sources', payload.sources);
        renderRanking('goals', payload.goals);
        renderRanking('authors', payload.authors);
        renderRanking('sections', payload.sections);
        renderTechnology(payload.technology);
        renderRealtime(payload.realtime);
        updateExports(bounds);
        updateGroups();
        updateEmptyState(
            traffic.some(([, item]) => Number(item.views) > 0 || Number(item.unique_count) > 0)
            || feedSeries.some(([, item]) => Number(item.unique_count) > 0),
        );
    };

    const applyRange = async (range) => {
        root.querySelectorAll('[data-analytics-range-days]').forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset.analyticsRangeDays === range ? 'true' : 'false');
        });
        const bounds = rangeBounds(range);
        currentBounds = bounds;
        updateRangeCaption(bounds);
        showLoading();
        const request = ++dashboardRequest;
        const payload = await loadReport('dashboard', bounds.fromDay, bounds.toDay);
        if (request !== dashboardRequest) return;
        renderDashboard(payload, bounds);
        clearError();
        hideLoading();
    };

    root.querySelectorAll('[data-analytics-range-days]').forEach((button) => {
        button.addEventListener('click', () => {
            void applyRange(button.dataset.analyticsRangeDays || '30').catch((error) => {
                showError(error instanceof Error ? error.message : (root.dataset.loadFailed || 'Unable to load analytics.'));
            });
        });
    });

    Promise.all([
        loadSeries('page').catch(() => []),
        loadSeries('feed:blog').catch(() => []),
    ])
        .then(async ([pages, feeds]) => {
            pageSeries = pages;
            feedSeries = feeds;
            const timestamps = [...pageSeries, ...feedSeries].map(([timestamp]) => timestamp);
            if (timestamps.length > 0) earliestTimestamp = Math.min(earliestTimestamp, ...timestamps);
            await applyRange(selectedRange());
        })
        .catch((error) => {
            showError(error instanceof Error ? error.message : (root.dataset.loadFailed || 'Unable to load analytics.'));
        });

    globalThis.window?.setInterval?.(() => {
        if (currentBounds === null || document.visibilityState === 'hidden') return;
        void loadReport('realtime', currentBounds.fromDay, currentBounds.toDay)
            .then(renderRealtime)
            .catch(() => {});
    }, 30000);
});
