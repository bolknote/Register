/* Live-update acknowledgment regressions using the production updater. */
const cases = [];
const responses = [];
const test = (name, run) => cases.push({name, run});

function equal(actual, expected, message = '') {
    if (actual !== expected) throw new Error(`${message}\nExpected: ${expected}\nActual: ${actual}`);
}

function ok(value, message) {
    if (!value) throw new Error(message);
}

window.fetch = async (input) => {
    const requestCursor = Number(new URL(input, document.baseURI).searchParams.get('cursor'));
    const payload = responses.shift() || {
        cursor: requestCursor,
        patches: {},
        causes: {},
        more: false,
    };

    return {
        ok: true,
        json: async () => payload,
    };
};

function setup(cursor, locked = false) {
    responses.length = 0;
    const config = document.querySelector('meta[name="register-live-updates"]');
    config.dataset.cursor = String(cursor);
    document.getElementById('fixture').innerHTML = `
        <div class="live-post-feed" data-live-region="posts:0">
            <article class="post-card${locked ? ' is-editing' : ''}">
                <p data-version="local">Locally applied post</p>
            </article>
        </div>`;
    ok(window.RegisterLiveUpdates.reconfigure(60000), 'The live updater must configure');
}

function waitForSynchronizations(count, action) {
    return new Promise((resolve, reject) => {
        let received = 0;
        const timeout = window.setTimeout(() => {
            document.removeEventListener('register:live-synchronized', onSynchronized);
            reject(new Error(`Timed out after ${received} of ${count} synchronizations`));
        }, 3000);
        const onSynchronized = () => {
            received += 1;
            if (received < count) return;
            window.clearTimeout(timeout);
            document.removeEventListener('register:live-synchronized', onSynchronized);
            resolve();
        };
        document.addEventListener('register:live-synchronized', onSynchronized);
        action();
    });
}

test('a locally applied mutation does not replace the feed again', async () => {
    setup(10, true);
    responses.push({
        cursor: 11,
        patches: {
            'posts:0': '<div class="live-post-feed" data-live-region="posts:0"><p data-version="duplicate">Duplicate server patch</p></div>',
        },
        causes: {'posts:0': [11]},
        more: false,
    });

    await waitForSynchronizations(1, () => {
        equal(window.RegisterLiveUpdates.acknowledge(11), true);
    });
    ok(document.querySelector('[data-version="local"]'), 'The local DOM must stay in place');
    equal(document.querySelector('[data-version="duplicate"]'), null);
});

test('a patch containing another browser mutation is still applied', async () => {
    setup(20);
    responses.push({
        cursor: 22,
        patches: {
            'posts:0': '<div class="live-post-feed" data-live-region="posts:0"><p data-version="concurrent">Concurrent result</p></div>',
        },
        causes: {'posts:0': [21, 22]},
        more: false,
    });

    await waitForSynchronizations(1, () => {
        equal(window.RegisterLiveUpdates.acknowledge(22), true);
    });
    ok(document.querySelector('[data-version="concurrent"]'), 'Concurrent changes must not be skipped');
});

test('split batches retain an earlier concurrent cause while the feed is locked', async () => {
    setup(30, true);
    responses.push(
        {
            cursor: 31,
            patches: {
                'posts:0': '<div class="live-post-feed" data-live-region="posts:0"><p>External change</p></div>',
            },
            causes: {'posts:0': [31]},
            more: true,
        },
        {
            cursor: 32,
            patches: {
                'posts:0': '<div class="live-post-feed" data-live-region="posts:0"><p data-version="merged">External and local changes</p></div>',
            },
            causes: {'posts:0': [32]},
            more: false,
        },
    );

    await waitForSynchronizations(2, () => {
        equal(window.RegisterLiveUpdates.acknowledge(32), true);
    });
    document.querySelector('.post-card').classList.remove('is-editing');
    document.dispatchEvent(new CustomEvent('register:live-unlock'));
    ok(document.querySelector('[data-version="merged"]'), 'The earlier external cause must keep the patch pending');
});

document.getElementById('run').addEventListener('click', async () => {
    const output = document.getElementById('results');
    const results = [];
    document.getElementById('run').disabled = true;
    for (const {name, run} of cases) {
        try { await run(); results.push({name, passed: true}); }
        catch (error) { results.push({name, passed: false, error: String(error)}); }
        output.textContent = results.map(result => `${result.passed ? 'PASS' : 'FAIL'} ${result.name}${result.error ? `\n${result.error}` : ''}`).join('\n');
    }
    output.dataset.finished = 'true';
    output.dataset.failed = String(results.filter(result => !result.passed).length);
    document.getElementById('run').disabled = false;
});
