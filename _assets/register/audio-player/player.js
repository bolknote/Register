/**
 * Register audio player implementation
 *
 * A dependency-free progressive enhancement for native HTML audio. The visual
 * design is inspired by Jouele; see THIRD_PARTY_NOTICES.md in this directory.
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

(function (document, window) {
    'use strict';

    if (!document || !window || window.RegisterAudioPlayer) {
        return;
    }

    const ownScript = document.currentScript;
    if (!ownScript || !ownScript.src) {
        return;
    }

    const assetBase = new URL('.', ownScript.src);
    const audioSelector = 'audio[controls]:not([data-register-audio-native])';
    const players = new Set();
    const playerByAudio = new WeakMap();
    let activeAudio = null;
    let observer = null;
    let scanQueued = false;
    let stylesheetPromise = null;

    const translations = {
        en: {
            player: 'Audio player',
            play: 'Play',
            pause: 'Pause',
            seek: 'Seek through audio',
            loading: 'Loading audio',
            playing: 'Playing',
            paused: 'Paused',
            ended: 'Playback finished',
            error: 'Audio could not be played',
            untitled: 'Audio recording',
            of: 'of',
        },
        ru: {
            player: 'Аудиоплеер',
            play: 'Воспроизвести',
            pause: 'Пауза',
            seek: 'Перемотать аудиозапись',
            loading: 'Аудиозапись загружается',
            playing: 'Воспроизведение',
            paused: 'Пауза',
            ended: 'Воспроизведение завершено',
            error: 'Не удалось воспроизвести аудиозапись',
            untitled: 'Аудиозапись',
            of: 'из',
        },
    };

    function languageFor(audio) {
        const languageNode = audio.closest('[lang]');
        const language = ((languageNode && languageNode.lang) || document.documentElement.lang || 'en').toLowerCase();

        return language.startsWith('ru') ? translations.ru : translations.en;
    }

    function formatTime(rawSeconds) {
        const value = Number(rawSeconds);
        if (!Number.isFinite(value) || value < 0) {
            return '0:00';
        }

        const totalSeconds = Math.floor(value);
        const seconds = totalSeconds % 60;
        const totalMinutes = Math.floor(totalSeconds / 60);
        const minutes = totalMinutes % 60;
        const hours = Math.floor(totalMinutes / 60);

        return (hours > 0 ? hours + ':' + String(minutes).padStart(2, '0') : minutes)
            + ':' + String(seconds).padStart(2, '0');
    }

    function audioSource(audio) {
        if (audio.currentSrc) {
            return audio.currentSrc;
        }
        if (audio.src) {
            return audio.src;
        }

        const source = audio.querySelector('source[src]');
        return source ? source.src : '';
    }

    function titleFromSource(source, fallback) {
        if (!source) {
            return fallback;
        }

        try {
            const pathname = new URL(source, document.baseURI).pathname;
            const filename = decodeURIComponent(pathname.slice(pathname.lastIndexOf('/') + 1));
            const title = filename.replace(/\.[^.]*$/, '').replace(/[_-]+/g, ' ').trim();
            return title || fallback;
        } catch (error) {
            return fallback;
        }
    }

    function audioTitle(audio, strings) {
        const explicitTitle = audio.dataset.title || audio.getAttribute('title') || audio.getAttribute('aria-label');
        if (explicitTitle && explicitTitle.trim()) {
            return explicitTitle.trim();
        }

        return titleFromSource(audioSource(audio), strings.untitled);
    }

    function finiteDuration(audio) {
        return Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : null;
    }

    function parseTimestamp(value) {
        if (typeof value !== 'string' && typeof value !== 'number') {
            return null;
        }
        const text = String(value).trim().replace(/^npt:/, '');
        if (!/^(?:\d+:){0,2}\d+(?:\.\d+)?$/.test(text)) {
            return null;
        }
        const parts = text.split(':').map(Number);
        if (parts.slice(1).some(function (part) { return part >= 60; })) {
            return null;
        }
        const seconds = parts.reduce(function (total, part) { return total * 60 + part; }, 0);
        return Number.isFinite(seconds) ? seconds : null;
    }

    function sourceUrl(source) {
        try {
            const url = new URL(source, document.baseURI);
            if (!/^https?:$/.test(url.protocol)) {
                return null;
            }
            url.hash = '';
            return url.href;
        } catch (error) {
            return null;
        }
    }

    function setProgressValue(progress, ratio) {
        progress.value = Math.round(Math.max(0, Math.min(1, ratio)) * 1000);
    }

    function createIcon() {
        const namespace = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(namespace, 'svg');
        svg.setAttribute('class', 'register-audio-player__icon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');

        const play = document.createElementNS(namespace, 'path');
        play.setAttribute('class', 'register-audio-player__icon-play');
        play.setAttribute('d', 'M7.5 4.8v14.4L19 12z');

        const pauseLeft = document.createElementNS(namespace, 'path');
        pauseLeft.setAttribute('class', 'register-audio-player__icon-pause');
        pauseLeft.setAttribute('d', 'M6.5 5h4v14h-4z');

        const pauseRight = document.createElementNS(namespace, 'path');
        pauseRight.setAttribute('class', 'register-audio-player__icon-pause');
        pauseRight.setAttribute('d', 'M13.5 5h4v14h-4z');

        svg.append(play, pauseLeft, pauseRight);
        return svg;
    }

    function listen(state, target, eventName, listener, options) {
        target.addEventListener(eventName, listener, options);
        state.removeListeners.push(function () {
            target.removeEventListener(eventName, listener, options);
        });
    }

    function announce(state, message) {
        if (state.status.textContent === message) {
            return;
        }

        state.status.textContent = '';
        window.requestAnimationFrame(function () {
            if (state.wrapper.isConnected) {
                state.status.textContent = message;
            }
        });
    }

    function updateBuffer(state) {
        const duration = finiteDuration(state.audio);
        const ranges = [];
        if (duration !== null) {
            const buffered = state.audio.buffered;
            for (let index = 0; index < buffered.length; index++) {
                const start = Math.max(0, buffered.start(index));
                const end = Math.min(duration, buffered.end(index));
                if (Number.isFinite(start) && Number.isFinite(end) && end > start) {
                    ranges.push([start / duration * 1000, (end - start) / duration * 1000]);
                }
            }
        }
        const signature = JSON.stringify(ranges);
        if (signature === state.bufferSignature) {
            return;
        }
        state.bufferSignature = signature;
        state.bufferedProgress.replaceChildren(...ranges.map(function ([start, width]) {
            const range = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            range.setAttribute('x', String(start));
            range.setAttribute('width', String(width));
            range.setAttribute('height', '1');
            return range;
        }));
    }

    function updateTime(state) {
        const duration = finiteDuration(state.audio);
        let currentTime = state.pendingSeek ?? (Number.isFinite(state.audio.currentTime) ? state.audio.currentTime : 0);
        if (state.scrubbing && duration !== null) {
            currentTime = duration * Number(state.timeline.value) / 1000;
        } else if (duration !== null) {
            currentTime = Math.min(duration, currentTime);
        }

        state.currentTime.textContent = formatTime(currentTime);
        state.currentTime.dateTime = 'PT' + Math.max(0, currentTime) + 'S';

        if (duration === null) {
            state.duration.textContent = '–:––';
            state.duration.removeAttribute('datetime');
            state.timeline.value = '0';
            state.timeline.disabled = true;
            state.timeline.removeAttribute('aria-valuetext');
            setProgressValue(state.playedProgress, 0);
            updateBuffer(state);
            return;
        }

        const ratio = Math.max(0, Math.min(1, currentTime / duration));
        state.duration.textContent = formatTime(duration);
        state.duration.dateTime = 'PT' + duration + 'S';
        state.timeline.disabled = false;
        if (!state.scrubbing) {
            state.timeline.value = String(Math.round(ratio * 1000));
        }
        state.timeline.setAttribute(
            'aria-valuetext',
            formatTime(currentTime) + ' ' + state.strings.of + ' ' + formatTime(duration),
        );
        setProgressValue(state.playedProgress, ratio);
        updateBuffer(state);
    }

    function updatePlaybackState(state) {
        const isPlaying = state.playRequested || (!state.audio.paused && !state.audio.ended);
        state.wrapper.classList.toggle('register-audio-player--playing', isPlaying);
        state.playButton.setAttribute('aria-label', isPlaying ? state.strings.pause : state.strings.play);
        state.playButton.title = isPlaying ? state.strings.pause : state.strings.play;
    }

    function setLoading(state, isLoading) {
        state.wrapper.classList.toggle('register-audio-player--loading', isLoading);
        if (isLoading) {
            announce(state, state.strings.loading);
        }
    }

    function setError(state) {
        state.pendingSeek = null;
        state.playRequested = false;
        state.playRequestId++;
        setLoading(state, false);
        updatePlaybackState(state);
        state.wrapper.classList.add('register-audio-player--error');
        state.playButton.disabled = true;
        state.timeline.disabled = true;
        announce(state, state.strings.error);
    }

    function updateLoading(state) {
        const active = state.playRequested || (!state.audio.paused && !state.audio.ended);
        setLoading(state, active && (state.pendingSeek !== null || state.audio.seeking || state.audio.readyState < 3));
    }

    function applyPendingSeek(state) {
        if (state.disposed || state.pendingSeek === null || state.audio.readyState < 1) {
            return false;
        }
        const duration = finiteDuration(state.audio);
        const position = duration === null ? state.pendingSeek : Math.min(duration, state.pendingSeek);
        try {
            // Let the media engine map time to byte ranges, including variable-bitrate files.
            state.audio.currentTime = position;
            state.pendingSeek = null;
            return true;
        } catch (error) {
            // Some media engines expose metadata before allowing a seek. Retry on canplay.
            return false;
        }
    }

    function requestSeek(state, position) {
        if (state.disposed || !Number.isFinite(position) || position < 0) {
            return;
        }
        state.pendingSeek = position;
        applyPendingSeek(state);
        updateLoading(state);
        updateTime(state);
    }

    function startPlayback(state) {
        if (state.disposed) {
            return;
        }
        if (activeAudio && activeAudio !== state.audio) {
            const previous = playerByAudio.get(activeAudio);
            if (previous) {
                pausePlayback(previous);
            } else {
                activeAudio.pause();
            }
        }
        activeAudio = state.audio;
        const requestId = ++state.playRequestId;
        state.playRequested = true;
        updatePlaybackState(state);
        updateLoading(state);
        function playFailed() {
            if (state.disposed || requestId !== state.playRequestId) {
                return;
            }
            state.playRequested = false;
            if (state.audio.error) {
                setError(state);
            } else {
                setLoading(state, false);
                updatePlaybackState(state);
            }
        }
        try {
            // Keep play() in the user's click, not in a later metadata callback.
            const playPromise = state.audio.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(playFailed);
            }
        } catch (error) {
            playFailed();
        }
    }

    function pausePlayback(state) {
        state.playRequested = false;
        state.playRequestId++;
        state.audio.pause();
        if (activeAudio === state.audio) {
            activeAudio = null;
        }
        setLoading(state, false);
        updatePlaybackState(state);
    }

    function togglePlayback(state) {
        if (state.playRequested || (!state.audio.paused && !state.audio.ended)) {
            pausePlayback(state);
        } else {
            startPlayback(state);
        }
    }

    function timestampClick(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')
            || link.matches('[data-register-native-navigation], [rel~="external"]')
            || link.closest('.is-editing, [contenteditable]:not([contenteditable="false"])')) {
            return;
        }
        let target;
        let timestamp;
        try {
            target = new URL(link.href, document.baseURI);
            const position = /^#t=([^&]+)$/.exec(target.hash);
            timestamp = position ? parseTimestamp(decodeURIComponent(position[1])) : null;
        } catch (error) {
            return;
        }
        const source = sourceUrl(target.href);
        if (timestamp === null || source === null) {
            return;
        }
        const candidates = Array.from(players).filter(function (state) {
            if (state.disposed || !state.wrapper.isConnected) {
                return false;
            }
            return [audioSource(state.audio), ...Array.from(state.audio.querySelectorAll('source[src]'), function (node) {
                return node.src;
            })].some(function (url) { return url && sourceUrl(url) === source; });
        });
        const post = link.closest('article, .comment');
        const state = candidates.find(function (candidate) { return post && post.contains(candidate.wrapper); }) || candidates[0];
        if (!state) {
            return;
        }
        event.preventDefault();
        requestSeek(state, timestamp);
        startPlayback(state);
        state.playButton.focus({preventScroll: true});
        state.wrapper.scrollIntoView({block: 'nearest'});
    }

    function bindPlayer(state) {
        listen(state, state.playButton, 'click', function () {
            togglePlayback(state);
        });
        listen(state, state.timeline, 'input', function () {
            const duration = finiteDuration(state.audio);
            if (duration !== null) {
                requestSeek(state, duration * Number(state.timeline.value) / 1000);
            }
        });
        listen(state, state.timeline, 'pointerdown', function () {
            state.scrubbing = true;
        });
        function finishScrubbing() {
            if (!state.scrubbing) {
                return;
            }
            state.scrubbing = false;
            updateTime(state);
        }
        listen(state, document, 'pointerup', finishScrubbing);
        listen(state, document, 'pointercancel', finishScrubbing);
        listen(state, state.timeline, 'blur', finishScrubbing);
        listen(state, state.audio, 'emptied', function () {
            state.pendingSeek = null;
            state.playRequested = false;
            state.playRequestId++;
            state.scrubbing = false;
            setLoading(state, false);
            updatePlaybackState(state);
            updateTime(state);
        });
        listen(state, state.audio, 'loadedmetadata', function () {
            state.wrapper.classList.remove('register-audio-player--error');
            state.playButton.disabled = false;
            applyPendingSeek(state);
            updateLoading(state);
            updateTime(state);
        });
        listen(state, state.audio, 'durationchange', function () {
            applyPendingSeek(state);
            updateTime(state);
        });
        listen(state, state.audio, 'timeupdate', function () {
            updateTime(state);
        });
        listen(state, state.audio, 'progress', function () {
            updateBuffer(state);
        });
        listen(state, state.audio, 'loadstart', function () {
            updateLoading(state);
        });
        listen(state, state.audio, 'waiting', function () {
            if (state.playRequested || !state.audio.paused) {
                setLoading(state, true);
            }
        });
        listen(state, state.audio, 'playing', function () {
            state.playRequested = false;
            setLoading(state, false);
            updatePlaybackState(state);
        });
        listen(state, state.audio, 'canplay', function () {
            applyPendingSeek(state);
            updateLoading(state);
        });
        listen(state, state.audio, 'seeking', function () {
            updateLoading(state);
        });
        listen(state, state.audio, 'seeked', function () {
            updateLoading(state);
            updateTime(state);
        });
        listen(state, state.audio, 'play', function () {
            if (state.audio.paused) {
                return;
            }
            if (activeAudio && activeAudio !== state.audio) {
                activeAudio.pause();
            }
            activeAudio = state.audio;
            updatePlaybackState(state);
            announce(state, state.strings.playing);
        });
        listen(state, state.audio, 'pause', function () {
            if (!state.audio.paused) {
                return;
            }
            state.playRequested = false;
            state.playRequestId++;
            if (activeAudio === state.audio) {
                activeAudio = null;
            }
            setLoading(state, false);
            updatePlaybackState(state);
            if (!state.audio.ended) {
                announce(state, state.strings.paused);
            }
        });
        listen(state, state.audio, 'ended', function () {
            state.playRequested = false;
            if (activeAudio === state.audio) {
                activeAudio = null;
            }
            setLoading(state, false);
            updatePlaybackState(state);
            updateTime(state);
            announce(state, state.strings.ended);
        });
        listen(state, state.audio, 'error', function () {
            setError(state);
        });
    }

    function createPlayer(audio) {
        const strings = languageFor(audio);
        const title = audioTitle(audio, strings);
        const wrapper = document.createElement('span');
        wrapper.className = 'register-audio-player';
        wrapper.setAttribute('role', 'group');
        wrapper.setAttribute('aria-label', strings.player + ': ' + title);

        const playButton = document.createElement('button');
        playButton.type = 'button';
        playButton.className = 'register-audio-player__play';
        playButton.setAttribute('aria-label', strings.play);
        playButton.title = strings.play;
        playButton.appendChild(createIcon());

        const timeline = document.createElement('input');
        timeline.type = 'range';
        timeline.className = 'register-audio-player__timeline';
        timeline.min = '0';
        timeline.max = '1000';
        timeline.step = '1';
        timeline.value = '0';
        timeline.disabled = true;
        timeline.setAttribute('aria-label', strings.seek);

        const timelineControl = document.createElement('span');
        timelineControl.className = 'register-audio-player__timeline-control';

        const bufferedProgress = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        bufferedProgress.setAttribute('class', 'register-audio-player__progress register-audio-player__progress--buffered');
        bufferedProgress.setAttribute('viewBox', '0 0 1000 1');
        bufferedProgress.setAttribute('preserveAspectRatio', 'none');
        bufferedProgress.setAttribute('focusable', 'false');
        bufferedProgress.setAttribute('aria-hidden', 'true');

        const playedProgress = document.createElement('progress');
        playedProgress.className = 'register-audio-player__progress register-audio-player__progress--played';
        playedProgress.max = 1000;
        playedProgress.value = 0;
        playedProgress.setAttribute('aria-hidden', 'true');

        timelineControl.append(bufferedProgress, playedProgress, timeline);

        const titleElement = document.createElement('span');
        titleElement.className = 'register-audio-player__title';
        titleElement.textContent = title;

        const time = document.createElement('span');
        time.className = 'register-audio-player__time';
        time.setAttribute('aria-hidden', 'true');
        const currentTime = document.createElement('time');
        currentTime.className = 'register-audio-player__current';
        currentTime.textContent = '0:00';
        const duration = document.createElement('time');
        duration.className = 'register-audio-player__duration';
        duration.textContent = '–:––';
        time.append(currentTime, document.createTextNode(' / '), duration);

        const status = document.createElement('span');
        status.className = 'register-audio-player__status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');

        const state = {
            audio: audio,
            wrapper: wrapper,
            playButton: playButton,
            timeline: timeline,
            bufferedProgress: bufferedProgress,
            playedProgress: playedProgress,
            title: titleElement,
            currentTime: currentTime,
            duration: duration,
            status: status,
            strings: strings,
            previousAriaHidden: audio.getAttribute('aria-hidden'),
            previousTabIndex: audio.getAttribute('tabindex'),
            removeListeners: [],
            bufferSignature: '',
            pendingSeek: null,
            playRequested: false,
            playRequestId: 0,
            scrubbing: false,
            disposed: false,
        };

        const parent = audio.parentNode;
        if (!parent) {
            return null;
        }

        parent.insertBefore(wrapper, audio);
        wrapper.append(audio, playButton, timelineControl, titleElement, time, status);
        audio.controls = false;
        audio.classList.add('register-audio-player__media');
        audio.setAttribute('aria-hidden', 'true');
        audio.setAttribute('tabindex', '-1');

        bindPlayer(state);
        updatePlaybackState(state);
        updateTime(state);
        if (!audioSource(audio)) {
            setError(state);
        }

        playerByAudio.set(audio, state);
        players.add(state);
        document.dispatchEvent(new CustomEvent('register:audio-player:ready', {
            detail: {audio: audio, player: wrapper},
        }));

        return state;
    }

    function disposePlayer(state) {
        state.disposed = true;
        state.pendingSeek = null;
        state.playRequested = false;
        state.playRequestId++;
        state.removeListeners.splice(0).forEach(function (removeListener) {
            removeListener();
        });
        state.audio.pause();
        if (activeAudio === state.audio) {
            activeAudio = null;
        }
        players.delete(state);
        playerByAudio.delete(state.audio);
    }

    function pruneDisconnectedPlayers() {
        players.forEach(function (state) {
            if (!state.wrapper.isConnected) {
                disposePlayer(state);
            }
        });
    }

    function audioElements(root) {
        if (!root || typeof root.querySelectorAll !== 'function') {
            return [];
        }

        const elements = Array.from(root.querySelectorAll(audioSelector));
        if (root.nodeType === 1 && root.matches(audioSelector)) {
            elements.unshift(root);
        }

        return elements;
    }

    function loadStylesheet() {
        if (stylesheetPromise) {
            return stylesheetPromise;
        }

        const existing = document.querySelector('link[data-register-audio-player-styles]');
        if (existing) {
            stylesheetPromise = Promise.resolve();
            return stylesheetPromise;
        }

        stylesheetPromise = new Promise(function (resolve, reject) {
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = new URL('player.css', assetBase).href;
            stylesheet.setAttribute('data-register-audio-player-styles', '');
            stylesheet.onload = resolve;
            stylesheet.onerror = function () {
                stylesheet.remove();
                reject(new Error('Unable to load local audio-player styles.'));
            };
            document.head.appendChild(stylesheet);
        }).catch(function (error) {
            stylesheetPromise = null;
            throw error;
        });

        return stylesheetPromise;
    }

    function updatePlayerMetadata(state) {
        const strings = languageFor(state.audio);
        const title = audioTitle(state.audio, strings);
        state.strings = strings;
        state.title.textContent = title;
        state.wrapper.setAttribute('aria-label', strings.player + ': ' + title);
        state.timeline.setAttribute('aria-label', strings.seek);
        updatePlaybackState(state);
        updateTime(state);
    }

    const api = {
        enhance: function (root) {
            const scope = root || document;
            pruneDisconnectedPlayers();
            const elements = audioElements(scope);
            if (elements.length === 0) {
                return Promise.resolve([]);
            }

            return loadStylesheet().then(function () {
                return elements.map(function (audio) {
                    if (!audio.isConnected) {
                        return null;
                    }
                    const existing = playerByAudio.get(audio);
                    if (existing && existing.wrapper.isConnected) {
                        updatePlayerMetadata(existing);
                        return existing.wrapper;
                    }

                    return createPlayer(audio)?.wrapper || null;
                }).filter(Boolean);
            });
        },
        refresh: function (root) {
            const scope = root || document;
            pruneDisconnectedPlayers();
            players.forEach(function (state) {
                if (scope === document || scope === state.wrapper || scope.contains(state.wrapper)) {
                    updatePlayerMetadata(state);
                }
            });

            return api.enhance(scope);
        },
    };

    function scheduleScan() {
        if (scanQueued) {
            return;
        }

        scanQueued = true;
        window.requestAnimationFrame(function () {
            scanQueued = false;
            api.enhance(document).catch(function (error) {
                console.error(error);
            });
        });
    }

    function startObserver() {
        if (observer || !document.body) {
            return;
        }

        observer = new MutationObserver(function (mutations) {
            const containsNewAudio = mutations.some(function (mutation) {
                return Array.from(mutation.addedNodes).some(function (node) {
                    return node.nodeType === 1
                        && (node.matches(audioSelector) || node.querySelector(audioSelector));
                });
            });

            if (containsNewAudio) {
                scheduleScan();
            } else {
                pruneDisconnectedPlayers();
            }
        });
        observer.observe(document.body, {childList: true, subtree: true});
    }

    window.RegisterAudioPlayer = api;
    // Run before partial navigation, but leave ordinary, modified and editor links alone.
    document.addEventListener('click', timestampClick, true);
    document.addEventListener('preview_updated.register', function (event) {
        if (event.detail && event.detail.wrapper) {
            api.enhance(event.detail.wrapper).catch(function (error) {
                console.error(error);
            });
        }
    });

    function initialize() {
        startObserver();
        api.enhance(document).catch(function (error) {
            console.error(error);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    } else {
        initialize();
    }
}(document, window));
