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
        if (duration === null || state.audio.buffered.length === 0) {
            setProgressValue(state.bufferedProgress, 0);
            return;
        }

        let bufferedEnd = 0;
        for (let index = 0; index < state.audio.buffered.length; index++) {
            bufferedEnd = Math.max(bufferedEnd, state.audio.buffered.end(index));
        }

        setProgressValue(state.bufferedProgress, bufferedEnd / duration);
    }

    function updateTime(state) {
        const duration = finiteDuration(state.audio);
        const currentTime = Number.isFinite(state.audio.currentTime) ? state.audio.currentTime : 0;

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
        state.timeline.value = String(Math.round(ratio * 1000));
        state.timeline.setAttribute(
            'aria-valuetext',
            formatTime(currentTime) + ' ' + state.strings.of + ' ' + formatTime(duration),
        );
        setProgressValue(state.playedProgress, ratio);
        updateBuffer(state);
    }

    function updatePlaybackState(state) {
        const isPlaying = !state.audio.paused && !state.audio.ended;
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
        setLoading(state, false);
        state.wrapper.classList.add('register-audio-player--error');
        state.playButton.disabled = true;
        state.timeline.disabled = true;
        announce(state, state.strings.error);
    }

    function seek(state) {
        const duration = finiteDuration(state.audio);
        if (duration === null) {
            return;
        }

        state.audio.currentTime = duration * (Number(state.timeline.value) / 1000);
        updateTime(state);
    }

    function togglePlayback(state) {
        if (state.audio.paused || state.audio.ended) {
            const playPromise = state.audio.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {
                    if (state.audio.error) {
                        setError(state);
                    }
                });
            }
            return;
        }

        state.audio.pause();
    }

    function bindPlayer(state) {
        listen(state, state.playButton, 'click', function () {
            togglePlayback(state);
        });
        listen(state, state.timeline, 'input', function () {
            seek(state);
        });
        listen(state, state.audio, 'loadedmetadata', function () {
            state.wrapper.classList.remove('register-audio-player--error');
            state.playButton.disabled = false;
            setLoading(state, false);
            updateTime(state);
        });
        listen(state, state.audio, 'durationchange', function () {
            updateTime(state);
        });
        listen(state, state.audio, 'timeupdate', function () {
            updateTime(state);
        });
        listen(state, state.audio, 'progress', function () {
            updateBuffer(state);
        });
        listen(state, state.audio, 'loadstart', function () {
            if (!state.audio.paused) {
                setLoading(state, true);
            }
        });
        listen(state, state.audio, 'waiting', function () {
            if (!state.audio.paused) {
                setLoading(state, true);
            }
        });
        listen(state, state.audio, 'playing', function () {
            setLoading(state, false);
        });
        listen(state, state.audio, 'canplay', function () {
            setLoading(state, false);
        });
        listen(state, state.audio, 'seeked', function () {
            setLoading(state, false);
        });
        listen(state, state.audio, 'play', function () {
            if (activeAudio && activeAudio !== state.audio) {
                activeAudio.pause();
            }
            activeAudio = state.audio;
            updatePlaybackState(state);
            announce(state, state.strings.playing);
        });
        listen(state, state.audio, 'pause', function () {
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

        const bufferedProgress = document.createElement('progress');
        bufferedProgress.className = 'register-audio-player__progress register-audio-player__progress--buffered';
        bufferedProgress.max = 1000;
        bufferedProgress.value = 0;
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
        state.removeListeners.splice(0).forEach(function (removeListener) {
            removeListener();
        });
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
