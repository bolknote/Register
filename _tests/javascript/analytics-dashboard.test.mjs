import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const dashboardSource = await readFile(
    new URL('../../_assets/register/analytics/charts.js', import.meta.url),
    'utf8'
);

function createHarness(pageSeries = []) {
    let ready;
    const chartCalls = [];
    const panels = Object.fromEntries(['pages', 'sessions', 'feeds'].map((name) => [name, {hidden: true}]));
    const rankingPanels = Object.fromEntries(['pages', 'sources'].map((name) => [name, {hidden: true}]));
    const rankingContainers = Object.fromEntries(['pages', 'sources'].map((name) => [name, {
        replaceChildren() {},
    }]));
    const loading = {hidden: false};
    const empty = {hidden: true};
    const error = {hidden: true, textContent: ''};
    const rankingGrid = {hidden: true};
    const buttons = ['14', '30', '90', 'all'].map((days) => ({
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
        },
        querySelector(selector) {
            if (selector === '[data-analytics-loading]') return loading;
            if (selector === '[data-analytics-empty]') return empty;
            if (selector === '[data-analytics-error]') return error;
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
            if (selector === '.analytics-summary-value') return [];
            if (selector === '[data-analytics-range-days]') return buttons;
            if (selector.includes('[data-analytics-panel]:not([hidden])')) {
                return [...Object.values(panels), ...Object.values(rankingPanels)]
                    .filter((panel) => !panel.hidden);
            }
            return [];
        },
    };
    const document = {
        documentElement: {lang: 'ru'},
        addEventListener(type, listener) {
            if (type === 'DOMContentLoaded') ready = listener;
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
            return {success: true, data: []};
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
