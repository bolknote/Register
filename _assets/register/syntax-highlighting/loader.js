(function (document, window) {
    'use strict';

    if (!document || !window || window.RegisterSyntaxHighlighting) {
        return;
    }

    const ownScript = document.currentScript;
    if (!ownScript || !ownScript.src) {
        return;
    }

    const assetBase = new URL('.', ownScript.src);
    const maximumCodeLength = 500000;
    const originalClassAttribute = 'data-register-syntax-original-class';
    const extensions = [];
    let highlighter = null;
    let highlighterPromise = null;

    function directCodeChild(pre) {
        for (let index = 0; index < pre.children.length; index++) {
            if (pre.children[index].tagName === 'CODE') {
                return pre.children[index];
            }
        }

        return null;
    }

    function legacyPreText(pre) {
        const clone = pre.cloneNode(true);
        clone.querySelectorAll('br').forEach(function (lineBreak) {
            lineBreak.replaceWith('\n');
        });

        return clone.textContent || '';
    }

    function codeElements(root) {
        if (!root || typeof root.querySelectorAll !== 'function') {
            return [];
        }

        const preElements = Array.from(root.querySelectorAll('pre'));
        if (root.nodeType === 1 && root.tagName === 'PRE') {
            preElements.unshift(root);
        }

        return preElements.map(function (pre) {
            let code = directCodeChild(pre);
            if (code) {
                return code;
            }

            code = document.createElement('code');
            code.textContent = legacyPreText(pre);
            pre.replaceChildren(code);

            return code;
        });
    }

    function highlightingIsDisabled(code) {
        const classes = code.className + ' ' + (code.parentElement ? code.parentElement.className : '');

        return /(?:^|\s)(?:nohighlight|no-highlight)(?:\s|$)/.test(classes);
    }

    function render(root) {
        if (!highlighter) {
            return;
        }

        codeElements(root).forEach(function (code) {
            if (highlightingIsDisabled(code)
                || code.dataset.highlighted === 'yes'
                || (code.textContent || '').length > maximumCodeLength
            ) {
                return;
            }

            try {
                if (!code.hasAttribute(originalClassAttribute)) {
                    code.setAttribute(originalClassAttribute, code.className);
                }
                highlighter.highlightElement(code);
            } catch (error) {
                console.error('Unable to highlight a code block.', error);
            }
        });
    }

    function loadStylesheet() {
        if (document.querySelector('link[data-register-syntax-highlighting-styles]')) {
            return Promise.resolve();
        }

        return new Promise(function (resolve, reject) {
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = new URL('theme.css', assetBase).href;
            stylesheet.setAttribute('data-register-syntax-highlighting-styles', '');
            stylesheet.onload = resolve;
            stylesheet.onerror = function () {
                reject(new Error('Unable to load local syntax-highlighting styles.'));
            };
            document.head.appendChild(stylesheet);
        });
    }

    function loadHighlightJs() {
        if (window.hljs && typeof window.hljs.highlightElement === 'function') {
            return Promise.resolve(window.hljs);
        }

        return new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = new URL('vendor/highlight.js/highlight.min.js', assetBase).href;
            script.async = true;
            script.setAttribute('data-register-syntax-highlighting-script', '');
            script.onload = function () {
                if (window.hljs && typeof window.hljs.highlightElement === 'function') {
                    resolve(window.hljs);
                } else {
                    reject(new Error('The local syntax highlighter did not initialize.'));
                }
            };
            script.onerror = function () {
                reject(new Error('Unable to load the local syntax highlighter.'));
            };
            document.head.appendChild(script);
        });
    }

    function applyExtension(extension) {
        try {
            extension(highlighter);
        } catch (error) {
            console.error('Unable to initialize a syntax-highlighting extension.', error);
        }
    }

    function initializeHighlighter() {
        if (!highlighterPromise) {
            highlighterPromise = Promise.all([loadStylesheet(), loadHighlightJs()])
                .then(function (result) {
                    highlighter = result[1];
                    extensions.splice(0).forEach(applyExtension);
                    document.dispatchEvent(new CustomEvent('register:syntax-highlighting:register', {
                        detail: {hljs: highlighter},
                    }));

                    return highlighter;
                })
                .catch(function (error) {
                    highlighterPromise = null;
                    throw error;
                });
        }

        return highlighterPromise;
    }

    const api = {
        use: function (extension) {
            if (typeof extension !== 'function') {
                throw new TypeError('A syntax-highlighting extension must be a function.');
            }

            if (highlighter) {
                applyExtension(extension);
                api.refresh(document).catch(function (error) {
                    console.error(error);
                });
            } else {
                extensions.push(extension);
            }

            return api;
        },
        highlight: function (root) {
            const scope = root || document;
            if (codeElements(scope).length === 0) {
                return Promise.resolve();
            }

            return initializeHighlighter().then(function () {
                render(scope);
                document.dispatchEvent(new CustomEvent('register:syntax-highlighting:ready', {
                    detail: {hljs: highlighter, root: scope},
                }));
            });
        },
        refresh: function (root) {
            const scope = root || document;
            codeElements(scope).forEach(function (code) {
                if (code.dataset.highlighted !== 'yes') {
                    return;
                }

                const text = code.textContent || '';
                code.className = code.getAttribute(originalClassAttribute) || '';
                code.textContent = text;
                delete code.dataset.highlighted;
                delete code.result;
                delete code.secondBest;
            });

            return api.highlight(scope);
        },
    };

    window.RegisterSyntaxHighlighting = api;
    api.highlight(document).catch(function (error) {
        console.error(error);
    });
}(document, window));
