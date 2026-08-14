/**
 * Finds changed ranges in corrected text without altering its whitespace or HTML.
 * Proofreading is expected to make small local edits, so a bounded look-ahead keeps
 * the comparison fast even for long articles.
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

const TOKEN_PATTERN = /<[^>]*>|[\p{L}\p{N}_]+|\s+|[^\p{L}\p{N}_\s<>]+|[<>]/gu;
const MAX_LOOK_AHEAD = 24;

function tokenize(text) {
    const result = [];
    let match;
    TOKEN_PATTERN.lastIndex = 0;
    while ((match = TOKEN_PATTERN.exec(text)) !== null) {
        result.push({
            text: match[0],
            start: match.index,
            end: match.index + match[0].length
        });
    }
    return result;
}

function findAnchor(before, after, beforeIndex, afterIndex) {
    let best = null;
    const beforeLimit = Math.min(before.length, beforeIndex + MAX_LOOK_AHEAD + 1);
    const afterLimit = Math.min(after.length, afterIndex + MAX_LOOK_AHEAD + 1);

    for (let i = beforeIndex; i < beforeLimit; i++) {
        for (let j = afterIndex; j < afterLimit; j++) {
            if (before[i].text !== after[j].text) {
                continue;
            }

            const distance = i - beforeIndex + j - afterIndex;
            if (best === null || distance < best.distance) {
                best = {beforeIndex: i, afterIndex: j, distance: distance};
            }
        }
    }

    return best;
}

function addRange(ranges, text, start, end) {
    while (start < end && /\s/u.test(text[start])) {
        start++;
    }
    while (end > start && /\s/u.test(text[end - 1])) {
        end--;
    }
    if (start === end) {
        return;
    }

    const previous = ranges[ranges.length - 1];
    if (previous && previous.end === start) {
        previous.end = end;
        return;
    }
    ranges.push({start: start, end: end});
}

/** @return {Array<{start: number, end: number}>} */
export function findCorrectionRanges(source, corrected) {
    if (source === corrected) {
        return [];
    }

    const before = tokenize(source);
    const after = tokenize(corrected);
    const ranges = [];
    let i = 0;
    let j = 0;

    while (i < before.length && j < after.length) {
        if (before[i].text === after[j].text) {
            i++;
            j++;
            continue;
        }

        const anchor = findAnchor(before, after, i, j);
        if (anchor === null) {
            addRange(ranges, corrected, after[j].start, corrected.length);
            return ranges;
        }

        if (anchor.afterIndex > j) {
            addRange(ranges, corrected, after[j].start, after[anchor.afterIndex - 1].end);
        }
        i = anchor.beforeIndex;
        j = anchor.afterIndex;
    }

    if (j < after.length) {
        addRange(ranges, corrected, after[j].start, corrected.length);
    }

    return ranges;
}
