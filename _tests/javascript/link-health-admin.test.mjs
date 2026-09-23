import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/link-health/admin.js', import.meta.url), 'utf8');

function harness({filterStatus = 'broken', responseStatus = 'ignored', requestOk = true} = {}) {
    const listeners = new Map();
    const actionStatus = {textContent: ''};
    const counters = {
        broken: [{textContent: '400'}, {textContent: '400'}],
        ignored: [{textContent: '0'}]
    };
    const badge = {className: 'link-health-status status-broken', textContent: 'Broken'};
    const repair = {removed: false, remove() { this.removed = true; }};
    const row = {
        dataset: {healthStatus: 'broken'},
        removed: false,
        querySelector(selector) {
            if (selector === '.link-health-status') return badge;
            if (selector === '.link-health-repair-action') return repair;
            return null;
        },
        remove() { this.removed = true; }
    };
    const toggle = {
        checked: true,
        disabled: false,
        dataset: {targetId: '17'},
        closest(selector) {
            if (selector === 'input[data-ignore-toggle]') return this;
            if (selector === 'tr') return row;
            return null;
        }
    };
    const root = {
        dataset: {
            actionEndpoint: '/action',
            csrfToken: 'token',
            filterStatus,
            workingMessage: 'Working',
            failureMessage: 'Failed'
        },
        querySelector(selector) {
            return selector === '.link-health-action-status' ? actionStatus : null;
        },
        querySelectorAll(selector) {
            const healthStatus = selector.match(/data-status-count="([^"]+)"/)?.[1];
            return counters[healthStatus] ?? [];
        },
        addEventListener(name, callback) { listeners.set(name, callback); }
    };
    const requests = [];
    const fetch = async (url, options) => {
        requests.push({url, options});
        return {
            ok: requestOk,
            async json() {
                return requestOk
                    ? {
                        success: true,
                        message: 'Saved',
                        health_status: responseStatus,
                        health_status_label: 'Ignored'
                    }
                    : {success: false, message: 'Rejected'};
            }
        };
    };
    const document = {querySelector: () => root};
    vm.runInNewContext(source, {document, fetch, URLSearchParams, Number, String, Error});

    return {listeners, actionStatus, counters, badge, repair, row, toggle, requests};
}

test('ignore switch updates counts and removes a row from the active filter without reloading', async () => {
    const state = harness();

    await state.listeners.get('change')({target: state.toggle});

    const body = state.requests[0].options.body;
    assert.equal(body.get('operation'), 'ignore');
    assert.equal(body.get('target_id'), '17');
    assert.equal(state.actionStatus.textContent, 'Saved');
    assert.equal(state.counters.broken[0].textContent, '399');
    assert.equal(state.counters.broken[1].textContent, '399');
    assert.equal(state.counters.ignored[0].textContent, '1');
    assert.equal(state.badge.className, 'link-health-status status-ignored');
    assert.equal(state.repair.removed, true);
    assert.equal(state.row.removed, true);
    assert.equal(state.toggle.disabled, false);
});

test('ignore switch rolls its visual state back when the request fails', async () => {
    const state = harness({requestOk: false});

    await state.listeners.get('change')({target: state.toggle});

    assert.equal(state.toggle.checked, false);
    assert.equal(state.toggle.disabled, false);
    assert.equal(state.actionStatus.textContent, 'Rejected');
    assert.equal(state.row.removed, false);
    assert.equal(state.counters.broken[0].textContent, '400');
});
