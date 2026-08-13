/**
 * Renders Register's privacy-conscious daily analytics on the authenticated dashboard.
 */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.register-analytics');
    if (root === null || typeof Highcharts === 'undefined') {
        return;
    }

    const endpoint = root.dataset.endpoint;
    if (endpoint === undefined) {
        return;
    }

    const load = async (channel) => {
        const response = await fetch(`${endpoint}&channel=${encodeURIComponent(channel)}`, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            throw new Error(`Analytics request failed with HTTP ${response.status}`);
        }

        const data = await response.json();
        return data.series.map((item) => [Date.parse(`${item.day}T00:00:00Z`), item]);
    };

    const draw = (id, series) => {
        Highcharts.stockChart(id, {
            chart: {panning: {enabled: true, type: 'x'}, panKey: 'alt', zoomType: 'x'},
            credits: {enabled: false},
            legend: {enabled: true},
            rangeSelector: {selected: 1},
            series,
        });
    };

    Promise.all([load('page'), load('feed:blog')])
        .then(([pages, blogFeed]) => {
            draw('register-analytics-pages', [
                {name: 'Page views', data: pages.map(([time, item]) => [time, item.hits])},
                {name: 'Unique visitors', color: '#64748b', data: pages.map(([time, item]) => [time, item.unique_count])},
            ]);
            draw('register-analytics-feeds', [
                {name: 'Blog feed readers', color: '#8b5cf6', data: blogFeed.map(([time, item]) => [time, item.unique_count])},
            ]);
        })
        .catch((error) => {
            const message = document.createElement('p');
            message.className = 'technical-data';
            message.textContent = error instanceof Error ? error.message : 'Unable to load analytics.';
            root.append(message);
        });
});
