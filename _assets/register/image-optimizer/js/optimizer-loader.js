/* Retry in an isolated same-origin realm, retaining the editor and its CSP. */
(() => {
    'use strict';
    const bridge = window.RegisterImageOptimizerLoader;
    if (bridge) import(bridge.url).then(bridge.resolve, bridge.reject);
})();
