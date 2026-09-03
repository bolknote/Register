import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/audio-player/player.js', import.meta.url), 'utf8');

class Events {
    listeners = new Map();
    addEventListener(name, callback) {
        if (!this.listeners.has(name)) this.listeners.set(name, new Set());
        this.listeners.get(name).add(callback);
    }
    removeEventListener(name, callback) { this.listeners.get(name)?.delete(callback); }
    emit(name, options = {}) {
        const event = {target: this, button: 0, defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; }, ...options};
        for (const callback of this.listeners.get(name) ?? []) callback(event);
        return event;
    }
    dispatchEvent(event) { this.emit(event.type, event); }
}

class Element extends Events {
    constructor(tagName) {
        super();
        this.tagName = tagName;
        this.nodeType = 1;
        this.children = [];
        this.attributes = new Map();
        this.dataset = {};
        this.classList = {
            add: (...names) => { this.className = [...new Set([...this.className.split(' '), ...names])].join(' ').trim(); },
            remove: (...names) => { this.className = this.className.split(' ').filter(name => !names.includes(name)).join(' '); },
            contains: name => this.className.split(' ').includes(name),
            toggle: (name, force) => (force ?? !this.classList.contains(name)) ? this.classList.add(name) : this.classList.remove(name),
        };
    }
    get className() { return this.getAttribute('class') ?? ''; }
    set className(value) { this.setAttribute('class', value); }
    get parentNode() { return this.parentElement; }
    get isConnected() { return Boolean(this.rootConnected || this.parentElement?.isConnected); }
    get textContent() { return this.text ?? this.children.map(child => child.textContent).join(''); }
    set textContent(text) { this.replaceChildren(); this.text = text; }
    get src() { return this.getAttribute('src') ? new URL(this.getAttribute('src'), 'http://localhost/').href : ''; }
    get href() { return new URL(this.getAttribute('href'), 'http://localhost/').href; }
    set href(value) { this.setAttribute('href', value); }
    get target() { return this.getAttribute('target'); }
    get lang() { return this.getAttribute('lang'); }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); }
    append(...children) { children.forEach(child => this.appendChild(child)); }
    appendChild(child) {
        child.remove?.();
        child.parentElement = this;
        this.children.push(child);
        this.onAppend?.(child);
        return child;
    }
    insertBefore(child, reference) {
        child.remove?.();
        child.parentElement = this;
        this.children.splice(this.children.indexOf(reference), 0, child);
    }
    replaceChildren(...children) {
        this.children.forEach(child => { child.parentElement = null; });
        this.children = [];
        this.text = undefined;
        this.append(...children);
    }
    remove() {
        if (this.parentElement) {
            this.parentElement.children = this.parentElement.children.filter(child => child !== this);
            this.parentElement = null;
        }
    }
    matches(selector) {
        return selector.split(',').some(part => {
            const excluded = [...part.matchAll(/:not\(([^)]+)\)/g)].map(match => match[1]);
            if (excluded.some(rule => this.matches(rule))) return false;
            const rule = part.replace(/:not\([^)]+\)/g, '').trim();
            const tag = rule.match(/^[a-z][\w-]*/)?.[0];
            if (tag && tag !== this.tagName) return false;
            if ([...rule.matchAll(/\.([\w-]+)/g)].some(match => !this.classList.contains(match[1]))) return false;
            return [...rule.matchAll(/\[([\w-]+)(?:(~?=)"([^"]*)")?\]/g)].every(([, key, op, value]) => {
                if (!this.hasAttribute(key)) return false;
                return !op || (op === '~=' ? this.getAttribute(key).split(/\s+/).includes(value) : this.getAttribute(key) === value);
            });
        });
    }
    querySelectorAll(selector) {
        return this.children.flatMap(child => child instanceof Element
            ? [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)] : []);
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    closest(selector) { return this.matches(selector) ? this : this.parentElement?.closest(selector) ?? null; }
    contains(element) { return element === this || this.children.some(child => child instanceof Element && child.contains(element)); }
    focus() { this.focused = true; }
    scrollIntoView() { this.scrolledIntoView = true; }
}

const timeRanges = ranges => ({length: ranges.length, start: index => ranges[index][0], end: index => ranges[index][1]});

class Audio extends Element {
    constructor(src) {
        super('audio');
        if (src) this.setAttribute('src', src);
        this.setAttribute('controls', '');
        this.readyState = 0;
        this.duration = NaN;
        this.paused = true;
        this.ended = false;
        this.seeking = false;
        this.error = null;
        this.currentSrc = '';
        this.position = 0;
        this.buffered = timeRanges([]);
        this.playCalls = 0;
        this.pauseCalls = 0;
        this.seekCalls = [];
    }
    get controls() { return this.hasAttribute('controls'); }
    set controls(value) { value ? this.setAttribute('controls', '') : this.removeAttribute('controls'); }
    get currentTime() { return this.position; }
    set currentTime(value) {
        if (this.readyState < 1 || this.rejectSeek) throw new Error('Media not ready');
        this.position = value;
        this.seekCalls.push(value);
    }
    metadata(duration = 3600) { this.duration = duration; this.readyState = 1; this.emit('loadedmetadata'); }
    canPlay() { this.readyState = 3; this.emit('canplay'); }
    play() {
        this.playCalls++;
        this.paused = false;
        this.ended = false;
        this.emit('play');
        return this.playResult ?? Promise.resolve();
    }
    pause() {
        this.pauseCalls++;
        const changed = !this.paused;
        this.paused = true;
        if (changed) this.emit('pause');
    }
}

async function harness(sources = ['/recording.wav'], options = {}) {
    const document = new Events();
    const html = new Element('html');
    html.setAttribute('lang', 'ru');
    html.rootConnected = true;
    document.head = new Element('head');
    document.body = new Element('body');
    html.append(document.head, document.body);
    document.documentElement = html;
    document.baseURI = 'http://localhost/';
    document.readyState = 'loading';
    document.currentScript = {src: 'http://localhost/_assets/register/audio-player/player.js'};
    document.querySelectorAll = selector => html.querySelectorAll(selector);
    document.querySelector = selector => html.querySelector(selector);
    document.createElement = tag => new Element(tag);
    document.createElementNS = (_, tag) => new Element(tag);
    document.createTextNode = text => ({textContent: text});
    document.head.onAppend = child => queueMicrotask(() => options.failStyles ? child.onerror?.() : child.onload?.());
    const audios = sources.map(src => new Audio(src));
    document.body.append(...audios);
    const observers = [];
    const frames = [];
    const window = {requestAnimationFrame: callback => frames.push(callback)};
    const context = vm.createContext({document, window, URL, Element, console,
        CustomEvent: class { constructor(type, detail) { this.type = type; this.detail = detail; } },
        MutationObserver: class {
            constructor(callback) { observers.push(callback); }
            observe() {}
        },
    });
    vm.runInContext(source, context);
    const api = window.RegisterAudioPlayer;
    if (!options.failStyles) {
        document.emit('DOMContentLoaded');
        await api.enhance(document);
    }
    const player = (index = 0) => audios[index].parentElement;
    const control = (name, index = 0) => player(index).querySelector(`.register-audio-player__${name}`);
    const link = (timestamp, index = 0, attributes = {}) => {
        const anchor = new Element('a');
        anchor.setAttribute('href', `${audios[index].src}#t=${timestamp}`);
        for (const [key, value] of Object.entries(attributes)) anchor.setAttribute(key, value);
        document.body.append(anchor);
        return anchor;
    };
    const click = (anchor, options = {}) => document.emit('click', {target: anchor, ...options});
    const remove = (index = 0) => {
        player(index).remove();
        observers.forEach(callback => callback([{addedNodes: []}]));
    };
    return {document, window, audios, api, player, control, link, click, remove, frames};
}

test('buffer ranges preserve unloaded gaps and only redraw when their boundaries change', async () => {
    const h = await harness();
    const audio = h.audios[0];
    audio.metadata(100);
    audio.buffered = timeRanges([[0, 5], [80, 85]]);
    audio.emit('progress');
    const buffer = h.control('progress--buffered');
    assert.equal(buffer.tagName, 'svg');
    assert.deepEqual(buffer.children.map(rect => [rect.getAttribute('x'), rect.getAttribute('width')]), [['0', '50'], ['800', '50']]);
    const first = buffer.children[0];
    audio.emit('timeupdate');
    assert.equal(buffer.children[0], first);
    audio.buffered = timeRanges([[-10, 10], [90, 120], [120, 150]]);
    audio.emit('progress');
    assert.deepEqual(buffer.children.map(rect => [rect.getAttribute('x'), rect.getAttribute('width')]), [['0', '100'], ['900', '100']]);
    audio.duration = Infinity;
    audio.emit('durationchange');
    assert.equal(buffer.children.length, 0);
});

test('timestamps start playback in the click and apply only the last request when metadata arrives', async () => {
    const h = await harness();
    assert.equal(h.control('timeline').disabled, true);
    assert.equal(h.click(h.link('12:35')).defaultPrevented, true);
    assert.equal(h.audios[0].playCalls, 1);
    assert.deepEqual(h.audios[0].seekCalls, []);
    assert.equal(h.control('current').textContent, '12:35');
    h.click(h.link('59:00'));
    h.audios[0].metadata();
    assert.deepEqual(h.audios[0].seekCalls, [3540]);
    assert.equal(h.control('timeline').value, '983');
    assert.equal(h.player().classList.contains('register-audio-player--loading'), true);
    h.audios[0].canPlay();
    assert.equal(h.player().classList.contains('register-audio-player--loading'), false);
});

test('pausing during loading keeps the requested position but never restarts playback', async () => {
    const h = await harness();
    h.click(h.link('42'));
    h.control('play').emit('click');
    assert.equal(h.audios[0].paused, true);
    h.audios[0].metadata();
    h.audios[0].canPlay();
    assert.equal(h.audios[0].currentTime, 42);
    assert.equal(h.audios[0].paused, true);
    assert.equal(h.audios[0].playCalls, 1);
    assert.equal(h.control('play').getAttribute('aria-label'), 'Воспроизвести');
});

test('a seek rejected by the media engine retries on canplay and clamps to the actual duration', async () => {
    const h = await harness();
    h.click(h.link('99999'));
    h.audios[0].rejectSeek = true;
    h.audios[0].metadata(90);
    assert.deepEqual(h.audios[0].seekCalls, []);
    h.audios[0].rejectSeek = false;
    h.audios[0].canPlay();
    assert.deepEqual(h.audios[0].seekCalls, [90]);
});

test('timestamp formats are strict and unsupported fragments retain normal navigation', async () => {
    const h = await harness();
    h.audios[0].metadata(10000);
    for (const [value, expected] of [['0', 0], ['12.5', 12.5], ['12:35', 755], ['1:12:35.5', 4355.5], ['npt:02:03', 123], ['12%3A35', 755]]) {
        assert.equal(h.click(h.link(value)).defaultPrevented, true, value);
        assert.equal(h.audios[0].currentTime, expected, value);
    }
    for (const value of ['', '-5', 'NaN', 'Infinity', '1e3', '1:60', '1:61:00', '1:2:3:4', '5,10', '12&other=1', '%ZZ']) {
        assert.equal(h.click(h.link(value)).defaultPrevented, false, value);
    }
});

test('modified, download, external and editor links are not intercepted', async () => {
    const h = await harness();
    for (const options of [{ctrlKey: true}, {metaKey: true}, {shiftKey: true}, {altKey: true}, {button: 1}]) {
        assert.equal(h.click(h.link('12'), options).defaultPrevented, false);
    }
    for (const attributes of [{download: ''}, {target: '_blank'}, {rel: 'external'}, {'data-register-native-navigation': ''}]) {
        assert.equal(h.click(h.link('12', 0, attributes)).defaultPrevented, false);
    }
    const editor = new Element('div');
    editor.setAttribute('contenteditable', 'true');
    h.document.body.append(editor);
    const anchor = h.link('12');
    editor.append(anchor);
    assert.equal(h.click(anchor).defaultPrevented, false);
    assert.equal(h.audios[0].playCalls, 0);
});

test('timestamps match full source URLs, including alternatives and query parameters', async () => {
    const h = await harness(['/recording.wav?version=1', '/recording.wav?version=2']);
    const alternative = new Element('source');
    alternative.setAttribute('src', '/recording.mp3');
    h.audios[1].append(alternative);
    const anchor = h.link('15');
    anchor.setAttribute('href', '/recording.mp3#t=15');
    assert.equal(h.click(anchor).defaultPrevented, true);
    assert.equal(h.audios[1].playCalls, 1);
    assert.equal(h.audios[0].playCalls, 0);
    for (const href of ['/recording.wav#t=15', 'https://another.example/recording.mp3#t=15', 'javascript:alert(1)#t=15']) {
        anchor.setAttribute('href', href);
        assert.equal(h.click(anchor).defaultPrevented, false);
    }
});

test('a timestamp prefers the matching player in its own article', async () => {
    const h = await harness(['/recording.wav', '/recording.wav']);
    const article = new Element('article');
    h.document.body.append(article);
    article.append(h.player(1));
    const anchor = h.link('10');
    article.append(anchor);
    h.click(anchor);
    assert.equal(h.audios[0].playCalls, 0);
    assert.equal(h.audios[1].playCalls, 1);
});

test('timeupdate does not move the slider from under a dragging pointer', async () => {
    const h = await harness();
    const audio = h.audios[0];
    audio.metadata(100);
    const slider = h.control('timeline');
    slider.emit('pointerdown');
    slider.value = '800';
    slider.emit('input');
    assert.equal(audio.currentTime, 80);
    audio.position = 79;
    audio.emit('timeupdate');
    assert.equal(slider.value, '800');
    h.document.emit('pointercancel');
    assert.equal(slider.value, '790');
});

test('starting a second player pauses the first even while it is loading', async () => {
    const h = await harness(['/one.wav', '/two.wav']);
    h.click(h.link('10'));
    h.click(h.link('20', 1));
    assert.equal(h.audios[0].paused, true);
    assert.equal(h.audios[1].paused, false);
    h.audios[0].metadata();
    assert.equal(h.audios[0].paused, true);
    assert.equal(h.audios[0].playCalls, 1);
});

test('removing a loading player pauses it, removes listeners and discards queued seeks', async () => {
    const h = await harness();
    h.click(h.link('55'));
    h.remove();
    assert.equal(h.audios[0].paused, true);
    h.audios[0].metadata();
    assert.deepEqual(h.audios[0].seekCalls, []);
    assert.ok([...h.audios[0].listeners.values()].every(listeners => listeners.size === 0));
    assert.equal(h.document.listeners.get('pointerup').size, 0);
    assert.equal(h.click(h.link('50')).defaultPrevented, false);
});

test('a late rejected play promise does not cancel a newer request or touch a removed player', async () => {
    const h = await harness();
    let reject;
    h.audios[0].playResult = new Promise((_, failure) => { reject = failure; });
    h.control('play').emit('click');
    h.control('play').emit('click');
    h.audios[0].playResult = Promise.resolve();
    h.control('play').emit('click');
    reject(new Error('The old play() was interrupted'));
    await Promise.resolve();
    assert.equal(h.control('play').getAttribute('aria-label'), 'Пауза');
    h.remove();
    assert.equal(h.audios[0].paused, true);
});

test('an old pause event cannot hide a newer loading request', async () => {
    const h = await harness();
    h.control('play').emit('click');
    h.control('play').emit('click');
    h.control('play').emit('click');
    h.audios[0].emit('pause');
    assert.equal(h.control('play').getAttribute('aria-label'), 'Пауза');
    assert.equal(h.player().classList.contains('register-audio-player--loading'), true);
});

test('malformed links and disposed players cannot break delegated clicks', async () => {
    const h = await harness();
    const anchor = h.link('10');
    Object.defineProperty(anchor, 'href', {get: () => 'http://[bad#t=10'});
    assert.equal(h.click(anchor).defaultPrevented, false);
    let reject;
    h.audios[0].playResult = new Promise((_, failure) => { reject = failure; });
    h.control('play').emit('click');
    const wrapper = h.player();
    h.remove();
    reject(new Error('Disconnected'));
    await Promise.resolve();
    assert.equal(wrapper.classList.contains('register-audio-player--error'), false);
    assert.equal(h.audios[0].paused, true);
});

test('native source reset clears pending position, while waiting and errors update controls', async () => {
    const h = await harness();
    h.click(h.link('42'));
    h.audios[0].paused = true;
    h.audios[0].emit('emptied');
    h.audios[0].metadata();
    assert.deepEqual(h.audios[0].seekCalls, []);
    h.control('play').emit('click');
    h.audios[0].emit('waiting');
    assert.equal(h.player().classList.contains('register-audio-player--loading'), true);
    h.audios[0].error = {code: 3};
    h.audios[0].paused = true;
    h.audios[0].emit('error');
    assert.equal(h.control('play').disabled, true);
    assert.equal(h.control('timeline').disabled, true);
    assert.equal(h.player().classList.contains('register-audio-player--loading'), false);
});

test('failed styles keep native controls, and removing audio during initialization does not wrap it', async () => {
    const options = {failStyles: true};
    const h = await harness(['/recording.wav'], options);
    await assert.rejects(h.api.enhance(h.document));
    assert.equal(h.audios[0].controls, true);
    assert.equal(h.document.head.children.length, 0);
    options.failStyles = false;
    const pending = h.api.enhance(h.document);
    h.audios[0].remove();
    assert.equal((await pending).length, 0);
    assert.equal(h.audios[0].controls, true);
});
