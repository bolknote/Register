const pause = 700;
const readingWordsPerMinute = 180;
const russianWord = /^[а-яё]+$/u;
const vowels = /[аеёиоуыэюя]/gu;
const skipped = 'pre, code, script, style, template, textarea, iframe, svg, math, audio, video, [hidden], '
    + '[data-post-html-source], .post-media-upload, .post-media-processing-progress, .post-media-caption-toolbar';
const blocks = 'p, div, section, article, aside, blockquote, li, dt, dd, td, th, figcaption, h1, h2, h3, h4, h5, h6, hr';
let wordList;

function prose(body) {
    const parts = [];
    // Walk the live DOM without cloning author HTML or measuring its layout.
    const parents = [];
    let node = body.firstChild;
    while (node) {
        if (node.nodeType === Node.TEXT_NODE) {
            parts.push(node.data);
        } else if (node instanceof Element) {
            if (node.matches(skipped)) {
                parts.push(node.matches('pre, [data-post-html-source]') ? '\n' : ' ');
            } else if (node.matches('br')) {
                parts.push(' ');
            } else {
                const boundary = node.matches(blocks);
                if (boundary) parts.push('\n');
                if (node.firstChild) {
                    parents.push({element: node, boundary});
                    node = node.firstChild;
                    continue;
                }
            }
        }
        while (!node.nextSibling && parents.length) {
            const parent = parents.pop();
            if (parent.boundary) parts.push('\n');
            node = parent.element;
        }
        node = node.nextSibling;
    }
    return parts.join('').replace(/[\u00ad\u200b\u2060\ufeff]/gu, '').trim();
}

function measure(text, sentenceSegmenter, wordSegmenter, commonWords) {
    let words = 0;
    let sentences = 0;
    let long = 0;
    let russianWords = 0;
    let syllables = 0;
    let multisyllabic = 0;
    let rare = 0;
    const distinct = new Set();
    for (const {segment} of sentenceSegmenter.segment(text)) {
        let length = 0;
        for (const {segment: token, isWordLike} of wordSegmenter.segment(segment)) {
            if (!isWordLike || !/\p{L}/u.test(token)) continue;
            const word = token.normalize('NFC').toLowerCase().replace(/\u0301/gu, '');
            distinct.add(word);
            length++;
            if (!russianWord.test(word)) continue;
            const count = word.match(vowels)?.length || 0;
            if (count === 0) continue;
            russianWords++;
            syllables += count;
            if (count >= 4) multisyllabic++;
            if (commonWords && !commonWords.has(word.replaceAll('ё', 'е'))) rare++;
        }
        if (length > 0) sentences++;
        if (length >= 25) long++;
        words += length;
    }

    const russianText = russianWords > words / 2;
    const average = sentences ? words / sentences : null;
    const meanSyllables = russianText ? syllables / russianWords : null;
    // Oborneva's Russian Reading Ease and the school-textbook adaptation
    // of Flesch–Kincaid by Solovyev, Ivanov and Solnyshkina (2018).
    return {
        words,
        seconds: words ? Math.max(1, Math.round(words * 60 / readingWordsPerMinute)) : null,
        sentence: average,
        long,
        rare: russianWords && commonWords ? rare : null,
        syllables: meanSyllables,
        flesch: russianText ? Math.round(206.836 - 1.3 * average - 60.1 * meanSyllables) : null,
        grade: russianText ? 0.36 * average + 5.76 * meanSyllables - 11.97 : null,
        matskovsky: russianText ? 0.62 * average + 0.123 * (multisyllabic * 100 / russianWords) + 0.051 : null,
        variety: words ? distinct.size / words : null,
        russianWords,
    };
}

function loadWords() {
    wordList ??= import('./ru-common.js').then(({default: words}) => new Set(words.split(' '))).catch(() => null);
    return wordList;
}

export function createReadability(state, template, signal, isComposing) {
    const panel = template.content.firstElementChild.cloneNode(true);
    const {card, body} = state;
    const fields = new Map(Array.from(panel.querySelectorAll('[data-readability-value]'),
        field => [field.dataset.readabilityValue, field]));
    const locale = document.documentElement.lang || 'en';
    const sentenceSegmenter = new Intl.Segmenter('ru', {granularity: 'sentence'});
    const wordSegmenter = new Intl.Segmenter(locale, {granularity: 'word'});
    const number = new Intl.NumberFormat(locale, {maximumFractionDigits: 1});
    const percent = new Intl.NumberFormat(locale, {style: 'percent', maximumFractionDigits: 0});
    const seconds = new Intl.NumberFormat(locale, {style: 'unit', unit: 'second', unitDisplay: 'narrow'});
    const minutes = new Intl.NumberFormat(locale, {style: 'unit', unit: 'minute', unitDisplay: 'short', maximumFractionDigits: 1});
    let commonWords = null;
    let dictionaryRequested = false;
    let previousText;
    let timer = 0;
    let frame = 0;

    function update() {
        timer = 0;
        panel.removeAttribute('data-pending');
        if (panel.hidden || isComposing() || !body.isConnected) return;
        const text = prose(body);
        if (text === previousText) return;
        previousText = text;
        const metrics = measure(text, sentenceSegmenter, wordSegmenter, commonWords);
        fields.forEach((field, key) => {
            const value = metrics[key];
            let formatted = '—';
            if (value !== null) {
                if (key === 'variety') formatted = percent.format(value);
                else if (key === 'seconds') formatted = '≈ ' + (value < 60
                    ? seconds.format(value) : minutes.format(value / 60));
                else formatted = number.format(value);
            }
            if (field.textContent !== formatted) field.textContent = formatted;
        });
        if (metrics.russianWords > 0 && !dictionaryRequested) {
            dictionaryRequested = true;
            loadWords().then(words => {
                if (!words || signal.aborted) return;
                commonWords = words;
                previousText = undefined;
                if (!timer && !isComposing()) update();
            });
        }
    }

    function schedule() {
        clearTimeout(timer);
        timer = 0;
        if (panel.hidden || isComposing()) return;
        panel.setAttribute('data-pending', '');
        timer = setTimeout(update, pause);
    }

    function place() {
        frame = 0;
        if (!body.isConnected) return;
        const bounds = body.getBoundingClientRect();
        const origin = card.getBoundingClientRect();
        const gap = 24;
        // Leave a gutter against the viewport or scrollbar. The panel is
        // outside the reading column and never changes its dimensions.
        if (!bounds.width || !Number.isFinite(width)
            || bounds.right + gap + width + 16 > document.documentElement.clientWidth) {
            panel.hidden = true;
            clearTimeout(timer);
            timer = 0;
            panel.removeAttribute('data-pending');
            return;
        }
        panel.style.left = (bounds.right - origin.left - card.clientLeft + gap) + 'px';
        panel.style.top = (bounds.top - origin.top - card.clientTop) + 'px';
        if (panel.hidden) {
            panel.hidden = false;
            schedule();
        }
    }

    function schedulePlacement() {
        if (!frame) frame = requestAnimationFrame(place);
    }

    card.append(panel);
    const width = Number.parseFloat(getComputedStyle(panel).width);
    const resizeObserver = new ResizeObserver(schedulePlacement);
    [card, body, state.title, state.time.parentElement, document.documentElement]
        .forEach(element => resizeObserver.observe(element));
    const observer = new MutationObserver(changes => {
        if (changes.some(change => {
            const element = change.target.nodeType === Node.ELEMENT_NODE ? change.target : change.target.parentElement;
            // A visibility change of a paragraph can add or remove prose.
            return change.type === 'attributes' || !element?.closest(skipped);
        })) schedule();
    });
    observer.observe(body, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['hidden']});
    body.addEventListener('input', event => { if (!event.isComposing) schedule(); }, {signal});
    body.addEventListener('compositionstart', () => {
        clearTimeout(timer);
        timer = 0;
        panel.removeAttribute('data-pending');
    }, {signal});
    body.addEventListener('compositionend', schedule, {signal});
    window.addEventListener('resize', schedulePlacement, {signal});
    signal.addEventListener('abort', () => {
        observer.disconnect();
        resizeObserver.disconnect();
        clearTimeout(timer);
        cancelAnimationFrame(frame);
        panel.remove();
    }, {once: true});
    place();
}
