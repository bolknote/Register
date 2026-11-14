const sentenceSegmenter = new Intl.Segmenter('ru', {granularity: 'sentence'});
const wordSegmenter = new Intl.Segmenter('ru', {granularity: 'word'});
const russianWord = /^[а-яё]+(?:-[а-яё]+)*$/u;
const letter = /\p{L}/u;
const hyphen = /[-‐‑]/u;
const vowels = /[аеёиоуыэюя]/gu;
const readingWordsPerMinute = 180;

function sentenceSource(text) {
    // ICU treats some periods in Russian abbreviations as sentence ends.
    // Protect initials before names, common prefixes, and the internal dots
    // of multi-part abbreviations. Keep the final dot of “и т. д.” eligible.
    const protect = abbreviation => abbreviation.replaceAll('.', ',');
    return text
        .replace(/(?<![\p{L}\p{N}])[А-ЯЁ]\.[^\S\r\n]*(?=[А-ЯЁ]\.)/gu, protect)
        .replace(/(?<![\p{L}\p{N}])[А-ЯЁ]\.[^\S\r\n]*(?=[А-ЯЁ][а-яё])/gu, protect)
        .replace(/(?:^|(?<!\p{L})(?:[Вв]|[Ии]з|[Дд]о|[Пп]од)[^\S\r\n]+)г\.(?=[^\S\r\n]+[А-ЯЁ])/gmu, protect)
        .replace(/(?<!\p{L})(?:ул|им|проф)\.(?=[^\S\r\n]+[А-ЯЁ])/gu, protect)
        .replace(/(?<!\p{L})(?:стр|рис|табл|п)\.(?=[^\S\r\n]+\d)/giu, protect)
        .replace(/(?<!\p{L})т\.(?=[^\S\r\n]*[едпокн]\.)/giu, protect)
        .replace(/(?<!\p{L})т,[^\S\r\n]*е\.(?=[^\S\r\n]+\p{L})/giu, protect);
}

function* wordsIn(sentence) {
    let word = '';
    let end = 0;
    for (const {segment, index, isWordLike} of wordSegmenter.segment(sentence)) {
        if (!isWordLike || !letter.test(segment)) continue;
        if (word && index === end + 1 && hyphen.test(sentence[end])) {
            word += '-' + segment;
        } else {
            if (word) yield word;
            word = segment;
        }
        end = index + segment.length;
    }
    if (word) yield word;
}

export function measureReadability(text) {
    let words = 0;
    let sentences = 0;
    let long = 0;
    let russianWords = 0;
    let russianSentences = 0;
    let syllables = 0;
    let multisyllabic = 0;
    const distinct = new Set();
    const russianForms = new Map();
    for (const {segment} of sentenceSegmenter.segment(sentenceSource(text))) {
        let length = 0;
        let russianLength = 0;
        for (const token of wordsIn(segment)) {
            const word = token.normalize('NFC').toLowerCase().replace(/\u0301/gu, '');
            distinct.add(word);
            length++;
            if (!russianWord.test(word)) continue;
            russianLength++;
            const count = word.match(vowels)?.length ?? 0;
            syllables += count;
            if (count >= 4) multisyllabic++;
            const form = word.replaceAll('ё', 'е');
            russianForms.set(form, (russianForms.get(form) ?? 0) + 1);
        }
        if (length > 0) sentences++;
        if (length >= 25) long++;
        if (russianLength > 0) russianSentences++;
        words += length;
        russianWords += russianLength;
    }

    const russianText = russianWords > words / 2 && syllables > 0;
    const russianAverage = russianText ? russianWords / russianSentences : null;
    const meanSyllables = russianText ? syllables / russianWords : null;
    // Both inputs to each adaptation describe the Russian part of the text.
    // Flesch: Oborneva. Flesch–Kincaid: Solovyev, Ivanov and Solnyshkina.
    const metrics = {
        words,
        seconds: words ? Math.max(1, Math.round(words * 60 / readingWordsPerMinute)) : null,
        sentence: sentences ? words / sentences : null,
        long,
        syllables: meanSyllables,
        flesch: russianText ? Math.round(206.836 - 1.3 * russianAverage - 60.1 * meanSyllables) : null,
        grade: russianText ? 0.36 * russianAverage + 5.76 * meanSyllables - 11.97 : null,
        matskovsky: russianText ? 0.62 * russianAverage + 0.123 * (multisyllabic * 100 / russianWords) + 0.051 : null,
        variety: words ? distinct.size / words : null,
    };
    return {metrics, russianForms};
}

export function countRareForms(russianForms, commonWords) {
    if (!russianForms.size || !commonWords) return null;
    let count = 0;
    for (const [word, occurrences] of russianForms) {
        // The frequency list contains the parts of compounds as individual forms.
        if (word.split('-').some(part => !commonWords.has(part))) count += occurrences;
    }
    return count;
}
