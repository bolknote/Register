/**
 * Register audio player loader
 *
 * Loads the dependency-free player only when native HTML audio is present.
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

(function (document, window) {
    'use strict';

    if (!document || !window || window.RegisterAudioPlayerLoader) {
        return;
    }

    const ownScript = document.currentScript;
    if (!ownScript || !ownScript.src) {
        return;
    }

    const assetBase = new URL('.', ownScript.src);
    const audioSelector = 'audio[controls]:not([data-register-audio-native])';
    let playerPromise = null;
    let observer = null;

    function containsAudio(root) {
        if (!root || typeof root.querySelector !== 'function') {
            return false;
        }

        return (root.nodeType === 1 && root.matches(audioSelector)) || Boolean(root.querySelector(audioSelector));
    }

    function loadPlayer() {
        if (window.RegisterAudioPlayer) {
            return Promise.resolve(window.RegisterAudioPlayer);
        }
        if (playerPromise) {
            return playerPromise;
        }

        playerPromise = new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = new URL('player.js', assetBase).href;
            script.async = true;
            script.setAttribute('data-register-audio-player-script', '');
            script.onload = function () {
                if (window.RegisterAudioPlayer) {
                    if (observer) {
                        observer.disconnect();
                        observer = null;
                    }
                    resolve(window.RegisterAudioPlayer);
                } else {
                    reject(new Error('The local audio player did not initialize.'));
                }
            };
            script.onerror = function () {
                reject(new Error('Unable to load the local audio player.'));
            };
            document.head.appendChild(script);
        }).catch(function (error) {
            playerPromise = null;
            throw error;
        });

        return playerPromise;
    }

    function enhance(root) {
        const scope = root || document;
        if (!containsAudio(scope)) {
            return Promise.resolve([]);
        }

        return loadPlayer().then(function (player) {
            return player.enhance(scope);
        });
    }

    const api = {enhance: enhance};
    window.RegisterAudioPlayerLoader = api;

    document.addEventListener('preview_updated.s2', function (event) {
        if (event.detail && event.detail.wrapper) {
            enhance(event.detail.wrapper).catch(function (error) {
                console.error(error);
            });
        }
    });

    function initialize() {
        enhance(document).catch(function (error) {
            console.error(error);
        });

        if (window.RegisterAudioPlayer || observer || !document.body) {
            return;
        }

        observer = new MutationObserver(function (mutations) {
            const scope = mutations.find(function (mutation) {
                return Array.from(mutation.addedNodes).find(containsAudio);
            });
            if (!scope) {
                return;
            }

            enhance(document).catch(function (error) {
                console.error(error);
            });
        });
        observer.observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    } else {
        initialize();
    }
}(document, window));
