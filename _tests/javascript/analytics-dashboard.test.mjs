import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const dashboardSource = await readFile(
    new URL('../../_assets/register/analytics/charts.js', import.meta.url),
    'utf8'
);

function createHarness(pageSeries = [], report = {}) {
    let ready;
    const chartCalls = [];
    const createNode = (tagName = '') => ({
        children: [],
        className: '',
        hidden: false,
        tagName,
        textContent: '',
        append(...children) {
            this.children.push(...children);
        },
        replaceChildren(...children) {
            this.children = children.flatMap((child) => child?.fragment === true ? child.children : [child]);
        },
    });
    const panels = Object.fromEntries(['pages', 'sessions', 'feeds'].map((name) => [name, {hidden: true}]));
    const rankingPanels = Object.fromEntries(['pages', 'sources', 'goals', 'authors', 'sections'].map((name) => [name, {hidden: true}]));
    const rankingContainers = Object.fromEntries(['pages', 'sources', 'goals', 'authors', 'sections'].map((name) => [name, {
        replaceChildren() {},
    }]));
    const loading = {hidden: false};
    const empty = {hidden: true};
    const error = {hidden: true, textContent: ''};
    const vitalsPanel = {hidden: true};
    const vitalsContainer = createNode('div');
    const rankingGrid = {
        hidden: true,
        querySelector() {
            return [...Object.values(panels), ...Object.values(rankingPanels), vitalsPanel]
                .find((panel) => !panel.hidden) ?? null;
        },
    };
    const buttons = ['7', '30', '90', 'all'].map((days) => ({
        dataset: {analyticsRangeDays: days},
        pressed: days === '30',
        addEventListener() {},
        setAttribute(name, value) {
            if (name === 'aria-pressed') {
                this.pressed = value === 'true';
            }
        },
    }));
    const root = {
        dataset: {
            analyticsRangeDays: '30',
            blogFeedReaders: 'Читатели RSS',
            bounces: 'Отказы',
            day: 'День',
            endpoint: '/series',
            loadFailed: 'Не удалось загрузить статистику.',
            pageViews: 'Просмотры страниц',
            reportEndpoint: '/report',
            requestFailed: 'Не удалось получить статистику',
            sessions: 'Сеансы',
            sourceDirect: 'Прямые заходы',
            today: '2026-08-30',
            uniqueVisitors: 'Уникальные посетители',
            vitalAverage: 'Среднее',
            vitalClsDescription: 'Стабильность страницы',
            vitalGood: 'Хорошо',
            vitalGoodSamples: 'Хороших измерений: %good% из %total%',
            vitalInpDescription: 'Отклик на действия',
            vitalInsufficient: 'Недостаточно данных',
            vitalLcpDescription: 'Появление основного содержимого',
            vitalNeeds: 'Нужно улучшить',
            vitalNoSamples: 'Пока нет измерений',
            vitalPoor: 'Плохо',
        },
        querySelector(selector) {
            if (selector === '[data-analytics-loading]') return loading;
            if (selector === '[data-analytics-empty]') return empty;
            if (selector === '[data-analytics-error]') return error;
            if (selector === '[data-analytics-vitals-panel]') return vitalsPanel;
            if (selector === '[data-analytics-vitals]') return vitalsContainer;
            if (selector === '.analytics-ranking-grid') return rankingGrid;
            if (selector === '[data-analytics-range-days][aria-pressed="true"]') {
                return buttons.find((button) => button.pressed) ?? null;
            }
            const panel = selector.match(/^\[data-analytics-panel="([a-z]+)"\]$/);
            if (panel) return panels[panel[1]] ?? null;
            const rankingPanel = selector.match(/^\[data-analytics-ranking-panel="([a-z]+)"\]$/);
            if (rankingPanel) return rankingPanels[rankingPanel[1]] ?? null;
            const ranking = selector.match(/^\[data-analytics-ranking="([a-z]+)"\]$/);
            if (ranking) return rankingContainers[ranking[1]] ?? null;
            return null;
        },
        querySelectorAll(selector) {
            if (selector === '[data-analytics-range-days]') return buttons;
            if (selector === '[data-analytics-group]') return [rankingGrid];
            return [];
        },
    };
    const document = {
        documentElement: {lang: 'ru'},
        addEventListener(type, listener) {
            if (type === 'DOMContentLoaded') ready = listener;
        },
        createDocumentFragment() {
            return {...createNode(), fragment: true};
        },
        createElement(tagName) {
            return createNode(tagName);
        },
        getElementById() { return null; },
        querySelector(selector) { return selector === '.register-analytics' ? root : null; },
    };
    const Highcharts = {
        chart(id, options) {
            chartCalls.push({id, options});
            return {
                redraw() {},
                reflow() {},
                series: options.series.map(() => ({setData() {}})),
            };
        },
        color(value) {
            return {
                get() { return value; },
                setOpacity() { return this; },
            };
        },
    };
    const fetch = async (url) => ({
        ok: true,
        async json() {
            if (url.includes('channel=page')) return {success: true, series: pageSeries};
            if (url.includes('channel=feed')) return {success: true, series: []};
            return {
                success: true,
                data: {
                    earliest_day: '2026-08-30',
                    summary: {
                        average_engagement: 0,
                        bounce_rate: 0,
                        pages_per_session: 0,
                        sessions: 0,
                        unique_count: 0,
                        views: 0,
                    },
                    comparison: {has_data: false, deltas: {}},
                    daily: [],
                    pages: [],
                    sources: [],
                    goals: [],
                    authors: [],
                    sections: [],
                    funnel: [],
                    vitals: report.vitals ?? [],
                    technology: {devices: [], browsers: [], systems: []},
                    realtime: {active_visitors: 0, views_30m: 0, pages: []},
                },
            };
        },
    });
    const context = vm.createContext({
        Highcharts,
        Intl,
        Map,
        Number,
        console,
        document,
        fetch,
        getComputedStyle() {
            return {getPropertyValue() { return '#888'; }};
        },
        navigator: {language: 'ru'},
    });

    new vm.Script(dashboardSource, {filename: 'charts.js'}).runInContext(context);
    ready();
    return {
        chartCalls,
        empty,
        error,
        loading,
        panels,
        rankingGrid,
        vitalsContainer,
        vitalsPanel,
        async settle() {
            await new Promise((resolve) => setImmediate(resolve));
            await new Promise((resolve) => setImmediate(resolve));
        },
    };
}

test('empty analytics never renders blank chart or ranking panels', async function () {
    const harness = createHarness();
    await harness.settle();

    assert.equal(harness.chartCalls.length, 0);
    assert.equal(Object.values(harness.panels).every((panel) => panel.hidden), true);
    assert.equal(harness.rankingGrid.hidden, true);
    assert.equal(harness.loading.hidden, true);
    assert.equal(harness.empty.hidden, false);
    assert.equal(harness.error.hidden, true);
});

test('web vitals explain small samples and keep missing metrics visible', async function () {
    const harness = createHarness([], {
        vitals: [{
            good_rate: 50,
            good_samples: 1,
            grade: 'insufficient',
            metric: 'LCP',
            samples: 2,
            unit: 'ms',
            value: 2648,
        }],
    });
    await harness.settle();

    assert.equal(harness.vitalsPanel.hidden, false);
    assert.equal(harness.vitalsContainer.children.length, 3);
    const [lcp, cls, inp] = harness.vitalsContainer.children;
    assert.equal(lcp.className, 'analytics-vital analytics-vital-insufficient');
    assert.deepEqual(lcp.children.map((child) => child.textContent), [
        'LCP',
        'Появление основного содержимого',
        'Среднее',
        '2 648 ms',
        'Недостаточно данных',
        'Хороших измерений: 1 из 2',
    ]);
    assert.equal(cls.className, 'analytics-vital analytics-vital-missing');
    assert.deepEqual(cls.children.map((child) => child.textContent), [
        'CLS',
        'Стабильность страницы',
        'Пока нет измерений',
    ]);
    assert.equal(inp.className, 'analytics-vital analytics-vital-missing');
    assert.deepEqual(inp.children.map((child) => child.textContent), [
        'INP',
        'Отклик на действия',
        'Пока нет измерений',
    ]);
});

test('traffic chart uses Russian dates and has no stock navigator controls', async function () {
    const harness = createHarness([{day: '2026-08-30', hits: 1200, unique_count: 450}]);
    await harness.settle();

    assert.equal(harness.chartCalls.length, 1);
    assert.equal(harness.panels.pages.hidden, false);
    assert.equal(harness.panels.sessions.hidden, true);
    assert.equal(harness.panels.feeds.hidden, true);
    assert.equal(harness.empty.hidden, true);

    const options = harness.chartCalls[0].options;
    assert.equal(options.navigator, undefined);
    assert.equal(options.rangeSelector, undefined);
    const label = options.xAxis.labels.formatter.call({value: Date.UTC(2026, 7, 30)});
    assert.match(label, /авг/i);
    assert.doesNotMatch(label, /Aug/);
});
