(function (document, window) {
    'use strict';

    if (!document || !window || window.RegisterMath) {
        return;
    }

    const ownScript = document.currentScript;
    if (!ownScript || !ownScript.src) {
        return;
    }

    const assetBase = new URL('.', ownScript.src);
    const ignoredTags = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'CODE', 'PRE', 'KBD']);
    const maximumFormulaLength = 10000;
    let rendererPromise = null;
    let layoutTimer = null;

    function findDelimiter(text, offset) {
        let delimiter = text.indexOf('$$', offset);

        while (delimiter !== -1) {
            let backslashCount = 0;
            let index = delimiter - 1;
            while (index >= 0 && text[index] === '\\') {
                backslashCount++;
                index--;
            }

            if (backslashCount % 2 === 0) {
                return delimiter;
            }

            delimiter = text.indexOf('$$', delimiter + 2);
        }

        return -1;
    }

    function splitByFormula(text) {
        const parts = [];
        let index = 0;
        let start = findDelimiter(text, index);

        while (start !== -1) {
            const end = findDelimiter(text, start + 2);
            if (end === -1) {
                break;
            }

            if (start > index) {
                parts.push({type: 'text', value: text.slice(index, start)});
            }
            parts.push({type: 'formula', value: text.slice(start + 2, end)});
            index = end + 2;
            start = findDelimiter(text, index);
        }

        if (index < text.length) {
            parts.push({type: 'text', value: text.slice(index)});
        }

        return parts.length ? parts : [{type: 'text', value: text}];
    }

    function acceptsTextNode(node, root) {
        if (!node.nodeValue || !node.nodeValue.includes('$$')) {
            return false;
        }

        let parent = node.parentNode;
        while (parent && parent !== root) {
            if (parent.nodeType === Node.ELEMENT_NODE) {
                if (
                    ignoredTags.has(parent.nodeName)
                    || parent.classList.contains('register-math-source')
                    || parent.classList.contains('comment-editor-surface')
                ) {
                    return false;
                }
            }
            parent = parent.parentNode;
        }

        return true;
    }

    function extractFormulaSlots(root) {
        const slots = Array.from(root.querySelectorAll('.register-math-source[data-register-math]'));
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const textNodes = [];
        let current;

        while ((current = walker.nextNode())) {
            if (acceptsTextNode(current, root)) {
                textNodes.push(current);
            }
        }

        textNodes.forEach(function (node) {
            const parts = splitByFormula(node.nodeValue || '');
            if (parts.length === 1 && parts[0].type === 'text') {
                return;
            }

            const fragment = document.createDocumentFragment();
            parts.forEach(function (part) {
                if (part.type === 'text') {
                    fragment.appendChild(document.createTextNode(part.value));
                    return;
                }

                const slot = document.createElement('span');
                slot.className = 'register-math-source';
                slot.setAttribute('data-register-math', part.value);
                slot.textContent = '$$' + part.value + '$$';
                slots.push(slot);
                fragment.appendChild(slot);
            });

            if (node.parentNode) {
                node.parentNode.replaceChild(fragment, node);
            }
        });

        return slots;
    }

    function isBlockFormula(slot) {
        const paragraph = slot.parentElement;
        if (!paragraph || paragraph.tagName !== 'P') {
            return false;
        }

        let formulaCount = 0;
        let surroundingText = '';
        for (let i = 0; i < paragraph.childNodes.length; i++) {
            const node = paragraph.childNodes[i];
            if (node === slot) {
                formulaCount++;
            } else if (node.nodeType === Node.TEXT_NODE) {
                surroundingText += node.textContent || '';
            } else {
                return false;
            }
        }

        return formulaCount === 1 && /^[ \t]*(?:\([ \t]*\S+[ \t]*\))?[ \t]*$/.test(surroundingText);
    }

    function loadStylesheet() {
        const existing = document.querySelector('link[data-register-math-styles]');
        if (existing) {
            return Promise.resolve();
        }

        return new Promise(function (resolve, reject) {
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = new URL('vendor/katex/katex-swap.min.css', assetBase).href;
            stylesheet.setAttribute('data-register-math-styles', '');
            stylesheet.onload = resolve;
            stylesheet.onerror = function () {
                reject(new Error('Unable to load local math styles.'));
            };
            document.head.appendChild(stylesheet);
        });
    }

    function loadKaTeX() {
        if (window.katex && typeof window.katex.render === 'function') {
            return Promise.resolve();
        }

        return new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = new URL('vendor/katex/katex.min.js', assetBase).href;
            script.async = true;
            script.setAttribute('data-register-math-script', '');
            script.onload = function () {
                if (window.katex && typeof window.katex.render === 'function') {
                    resolve();
                } else {
                    reject(new Error('The local math renderer did not initialize.'));
                }
            };
            script.onerror = function () {
                reject(new Error('Unable to load the local math renderer.'));
            };
            document.head.appendChild(script);
        });
    }

    function ensureRenderer() {
        if (!rendererPromise) {
            rendererPromise = Promise.all([loadStylesheet(), loadKaTeX()]).catch(function (error) {
                rendererPromise = null;
                throw error;
            });
        }

        return rendererPromise;
    }

    function renderSlot(slot) {
        if (slot.getAttribute('data-register-math-rendered') === '1') {
            return;
        }

        const formula = slot.getAttribute('data-register-math') || '';
        if (!formula || formula.length > maximumFormulaLength) {
            slot.classList.add('register-math-error');
            return;
        }

        const displayMode = isBlockFormula(slot);
        slot.classList.toggle('register-math-block', displayMode);

        try {
            window.katex.render(formula, slot, {
                displayMode: displayMode,
                maxExpand: 1000,
                maxSize: 20,
                output: 'htmlAndMathml',
                strict: 'warn',
                throwOnError: true,
                trust: false
            });
            slot.setAttribute('data-register-math-rendered', '1');
        } catch (error) {
            slot.classList.add('register-math-error');
            slot.textContent = '$$' + formula + '$$';
        }
    }

    function scheduleLayoutChange() {
        if (layoutTimer !== null) {
            return;
        }

        layoutTimer = window.setTimeout(function () {
            layoutTimer = null;
            document.dispatchEvent(new Event('preview_layout_changed.s2'));
        }, 50);
    }

    function render(root) {
        if (!root || typeof root.querySelectorAll !== 'function') {
            return Promise.resolve(false);
        }

        const slots = extractFormulaSlots(root);
        if (!slots.length) {
            return Promise.resolve(false);
        }

        return ensureRenderer().then(function () {
            slots.forEach(function (slot) {
                if (slot.isConnected) {
                    renderSlot(slot);
                }
            });
            scheduleLayoutChange();
            return true;
        }).catch(function () {
            return false;
        });
    }

    window.RegisterMath = {render: render};

    document.addEventListener('preview_updated.s2', function (event) {
        if (event.detail && event.detail.wrapper) {
            render(event.detail.wrapper);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            render(document.body);
        }, {once: true});
    } else {
        render(document.body);
    }
})(document, window);
