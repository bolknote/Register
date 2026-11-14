import {countRareForms, measureReadability} from './metrics.js';

let latest;
let commonWords;
let dictionary;

self.onmessage = ({data: {revision, text}}) => {
    const analysis = text === latest?.text ? latest.analysis : measureReadability(text);
    latest = {revision, text, analysis};
    const {metrics, russianForms} = analysis;
    self.postMessage({revision, metrics: {...metrics, rare: countRareForms(russianForms, commonWords)}});

    if (russianForms.size && !dictionary) {
        dictionary = import('./ru-common.js').then(({default: words}) => {
            commonWords = new Set(words.split(' '));
            // Reuse the word counts for the latest draft, even if it changed
            // during the download. The editor drops replies for older drafts.
            self.postMessage({
                revision: latest.revision,
                rare: countRareForms(latest.analysis.russianForms, commonWords),
            });
        }).catch(() => {
            // The other metrics remain available if the dictionary cannot load.
        });
    }
};
