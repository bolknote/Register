import assert from 'node:assert/strict';
import test from 'node:test';
import {countRareForms, measureReadability} from '../../_assets/register/editor/readability/metrics.js';

test('Russian scores include words with no written vowel and use the published formulas', () => {
    // Five words, five syllables. "В" occurs twice and has zero syllables.
    const {metrics} = measureReadability('В лесу и в поле.');
    assert.equal(metrics.words, 5);
    assert.equal(metrics.seconds, 2);
    assert.equal(metrics.sentence, 5);
    assert.equal(metrics.syllables, 1);
    assert.equal(metrics.flesch, 140);
    assert.ok(Math.abs(metrics.grade - (-4.41)) < 1e-10);
    assert.ok(Math.abs(metrics.matskovsky - 3.151) < 1e-10);
    assert.equal(metrics.variety, 4 / 5);

    // One of two words has more than three syllables, hence X4 = 50%.
    const difficult = measureReadability('Сверхпроводимость нужна.').metrics;
    assert.equal(difficult.syllables, 3.5);
    assert.equal(difficult.flesch, -6);
    assert.ok(Math.abs(difficult.matskovsky - 7.441) < 1e-10);
});

test('initials, common abbreviations and decimals leave the actual sentence boundaries', () => {
    const examples = [
        ['А. С. Пушкин жил в г. Москве. Потом уехал.', 9],
        ['А.С.Пушкин жил в г. Москве. Потом уехал.', 9],
        ['Я прочитал «Пушкин А. С.». Потом Лермонтова.', 7],
        ['На рис. 2 показана схема. Потом проверили.', 6],
        ['Мы читали стихи и т. д. Следующее упражнение.', 8],
        ['Это т. е. объяснение. Потом будет ещё.', 7],
        ['Версия 1.2.3. Потом всё заработало.', 4],
        ['В 2026 г. Мы уехали.', 4],
    ];
    for (const [text, words] of examples) {
        const {metrics} = measureReadability(text);
        assert.equal(metrics.words, words, text);
        assert.equal(metrics.sentence, words / 2, text);
    }
});

test('hyphenated words count once and use the frequency of their component forms', () => {
    const {metrics, russianForms} = measureReadability('По-русски, по‑русски. Кто-то ждёт, в чём дело.');
    assert.equal(metrics.words, 7);
    assert.equal(metrics.variety, 6 / 7);
    assert.equal(russianForms.get('по-русски'), 2);
    const commonWords = new Set(['по', 'кто', 'то', 'ждет', 'в', 'чем', 'дело']);
    assert.equal(countRareForms(russianForms, commonWords), 2);
    commonWords.add('русски');
    assert.equal(countRareForms(russianForms, commonWords), 0);
});

test('Russian formulas and overall length use matching inputs for a mixed-language note', () => {
    const russian = measureReadability('Я люблю тёплый свет.').metrics;
    const mixed = measureReadability('Я люблю тёплый свет. Ready to start.').metrics;
    assert.equal(mixed.words, 7);
    assert.equal(mixed.sentence, 3.5);
    for (const index of ['syllables', 'flesch', 'grade', 'matskovsky']) {
        assert.equal(mixed[index], russian[index], index);
    }

    const english = measureReadability('Ready to start.').metrics;
    assert.equal(english.words, 3);
    assert.equal(english.flesch, null);
    assert.equal(measureReadability('42 … 🙂').metrics.sentence, null);
});
