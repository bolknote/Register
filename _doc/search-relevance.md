# Search relevance

By default, Register ranks results by the number of matched significant query terms, then exact source forms,
then the existing relevance score. Matches may span the title, keywords and body. Each input term
counts once even when the Russian dictionary supplies several alternative lemmas, or when a word
appears in several fields. This ordering is applied before pagination.

Equal coverage, exactness and relevance are ordered by publication instant (newest first), then
serialized external ID in ascending order. Undated documents follow dated documents, including
dates before 1970. Dates respect the stored timezone. One global order determines both page
membership and output order, so concatenating pages matches an unpaginated result for an unchanged
index. Publication dates do not override the preceding relevance criteria.

Native SQL storage reads dates with its existing posting query; file storage supplies them from
TOC memory. Only the selected page's full TOC and snippets are loaded. Custom storage that has not
added date metadata to `FulltextIndexContent` remains supported through a TOC fallback.

Offsets are nonnegative and work with an unlimited result size. Search-page requests below page 1
or beyond the available pages show the actual first page; excessively large page integers are
checked before multiplying the offset. Page-count arithmetic also avoids integer overflow.

Common English and Russian articles, prepositions and conjunctions do not determine coverage or
exactness when the query contains other words. They remain indexed, searchable and highlighted;
connector-only queries retain their original behavior. Negation is not discarded.

No index-format change or rebuild is required for this ranking change. Existing exact-form and
lemma postings already supply the necessary information. The result trace includes
`matchedQueryTerms` and `exactQueryTerms` alongside the numerical relevance score.

## Quoted phrases

Use paired quotes to require an adjacent sequence of words in the given order:

```text
"история театра"
архив «красный цветок»
"красный цветок" "белый дом"
```

ASCII double quotes, guillemets, curly double quotes, low double quotes and paired curly single
quotes are accepted. Unpaired delimiters remain ordinary punctuation. Apostrophes in English
contractions do not start phrase constraints. Empty quotes are ignored.

The normal word normalization still applies: `"дети играют"` also matches `Ребёнок играл`.
Punctuation and inline markup are not significant. Every phrase must match entirely in one
field (title, keywords or body); separate phrases may match different fields of the same document.
All quoted phrases are mandatory, while words outside quotes continue to improve ranking without
becoming mandatory. Filtering happens before total counts, pagination, TOC loading and snippets,
and applies equally to search pages, RSS and JSON feeds.

Repeated words require distinct consecutive positions. Two components of one hyphenated word
share an index position and cannot satisfy a two-word phrase; `"well-known"` matches that token,
while `"well known"` requires two tokens. Connectors remain required when they have index postings.
If a file-backed index deliberately excludes common words, those terms leave positional gaps
without being checked, matching that storage's existing stop-word policy. SQL indexes retain them.

Queries retain the existing limit of 64 distinct lookup terms. A required phrase that cannot be
checked within that limit returns no results rather than silently matching only its prefix.
Phrase search uses the existing positions and needs no index rebuild.

## Result excerpts

Excerpt selection greedily prefers a fragment containing a previously unshown quoted phrase,
then previously unshown significant query terms, then the existing fragment relevance. Alternative
lemmas and exact-form postings of one input word count as one term. Connectors follow the same
significance policy as document ranking. At most three nonoverlapping, distinct excerpts are
selected and displayed in their original document order. A single-word search retains the usual
relevance-based choice once that word is shown.

Phrase occurrences come from the same positional matcher that filters results, including morphology,
repeated words and the storage's stop-word policy. A title/keyword occurrence is never presented as
an occurrence in the body. When a phrase crosses stored sentence boundaries, up to three adjacent
fragments of the same format can form one excerpt, limited to 4,096 bytes and three distinct additional
windows per phrase. Missing fragments, positional gaps, incompatible formats and oversized windows
are not joined or fabricated; ordinary matched fragments remain the fallback. Complete-phrase display
is therefore limited by the available stored fragments and excerpt budget.

Original escaping, internal formatting, highlight masks and custom separators remain in use. This
changes only excerpts, not document scores, result counts, ordering, pagination or feed membership.
It uses existing query postings and the selected page's stored fragments, with no new storage queries,
schema changes or index rebuild.

## Spelling and keyboard-layout suggestions

The search page offers an explicit correction link when the original result set does not contain
a match for every significant query term. The input, result count and original results remain
unchanged until the reader selects that link. RSS and JSON feeds keep their original query semantics.

Suggestions cover Russian / QWERTY keyboard-layout mistakes and a single insertion, deletion,
substitution or adjacent transposition in one alphabetic word. Edits operate on Unicode characters.
Spaces, paired quotes and capitalization are preserved; repeated occurrences of the same misspelled
word are corrected together. Queries whose words are known but fail a phrase constraint are not
relaxed into approximate phrases. Existing full matches, including unusual surnames, receive no
correction prompt.

The vocabulary lookup uses active index postings, not orphan word-table rows or an external
dictionary of proposed search results. Russian dictionary lemmas can verify a correctly spelled
base form even when only its inflections occur in the corpus. English candidates use source forms
from the index rather than displaying bare Porter stems. Every proposed complete query is checked
through the ordinary Finder, with the same phrase and instance constraints, before it is shown.

Work is bounded to 256 input characters, six distinct search words, one misspelled word of 4–24
letters, 4,096 lookup keys, five candidate validations and three displayed corrections. Only a
uniformly Latin or Russian query receives a whole-query keyboard-layout alternative. The optional
`IndexWordLookupInterface` performs indexed, batched existence checks without loading positions;
the original storage-read interface remains compatible. SQL and file-backed storage implement it.
No new index tables or rebuild are required.

## Transactional indexing

Word-ID caches are cleared on rollback. Indexing inside an externally managed transaction does
not retain tentative IDs across indexing calls: a later outer rollback could remove or reuse those
IDs. Locally owned successful transactions retain the ordinary cache optimization. This prevents
incorrect word associations when the same storage instance is reused after a failed transaction.

## Opt-in rarity ranking

The Finder supports a separate profile without changing the default or the index:

```php
use Register\Rose\Entity\RankingProfile;
use Register\Rose\Finder;

$finder = new Finder($storage, $normalizer, RankingProfile::Rarity);
```

This profile uses positive BM25-style IDF, `log(1 + (N-df+0.5)/(df+0.5))`,
normalized to weight 1 at `df=1`. Field coefficients, the native repeat bonus,
entry-size weighting, external relevance ratios and proximity geometry stay the
same; proximity frequency factors use the new IDF as well. Document frequency
and corpus size respect the requested instance.

For multiword queries, coverage becomes a score multiplier rather than an absolute
priority. The matched significant query groups' IDF weights are divided by the sum
of all groups' weights; this ratio is squared. An additional small exact-form bonus
is `1 + 0.1 * exactQueryTerms / queryTerms`. The underlying field/proximity score
is multiplied by both factors. Thus a focused title matching a rare term can rank
above a long document that incidentally matches every term. Missing query words
still contribute to the denominator, and all native candidates remain available.

Group frequency is the union of documents across the original term's exact and
alternative lemma keys. Fields and ambiguity do not multiply coverage. Exact-form
keys remain markers, not duplicate numerical contributions. Single-term exactness
remains a hard priority. Quoted phrases stay mandatory filters. Snippet selection,
total counts, date/ID tie-breaking and pagination use their existing mechanisms.
The trace adds `rankingProfile` and `weightedQueryCoverage` for this opt-in profile.

There is no public search-page switch or changed application wiring: existing
two-argument Finder construction retains the coverage profile. Native storage
supplies the same postings and dates, and only the selected page's TOC and snippets
are fetched. No schema change, index rebuild, dependency or external service is
required. Evaluate installation-specific queries before selecting this profile.

## Reproducible comparisons

Run the offline comparison with:

```bash
php tools/evaluate-search.php
php tools/evaluate-search.php --json
```

The bundled dataset contains 46 synthetic documents and 48 Russian/English queries. It covers
inflections, ambiguous morphology, multiword coverage, matches across fields, connectors,
historical spelling, keyword matches, quoted phrases and repeated words. It contains no production
article identifiers or production query logs, and its scores are not an estimate of live search quality.

The evaluator builds a separate SQLite index in memory and compares:

- `legacy`: the previous query parsing, unfiltered postings and exact-count-first comparator;
- `coverage`: the actual current Finder output;
- `bm25f`: an offline prototype that keeps coverage and exactness priorities, replacing the remaining
  relevance score with BM25F (`k1=1.2`, `b=0.75`, title/keyword/body weights `5/3/1`).

Legacy scoring is computed independently of the current Finder so that its phrase filtering cannot
silently restrict the baseline's candidate set. BM25F uses the current query syntax and the same
phrase constraints as public search.

The prototype measures each field's length from the indexed logical positions for the entire corpus.
Alternative lemmas share a query-term group, and matching positions are deduplicated within each
field before computing term frequency. Document frequency is the union of matching documents
across the group's alternatives. Exact-form keys do not multiply term frequency. The prototype
does not include the current phrase-proximity bonus and is not used by public search.

Document-ID order breaks score ties in the experimental comparators. The bundled documents have no
publication dates, so the legacy comparison does not evaluate the existing date tie-breaker.

Judgments use grades `3` (direct answer), `2` (relevant) and `1` (incidental). Unjudged documents
receive `0`. Hit@1, Hit@3 and MRR consider grades 2 and 3 successful. nDCG@10 uses graded gain
`2^grade - 1` and includes all judged documents in the ideal ranking, including documents the
search failed to retrieve. JSON output includes every query's rankings and metrics, making
individual regressions visible instead of hiding them in an average.

## Opt-in ranking experiments

The default public search behavior is unchanged. To compare additional profiles on
the same in-memory index, run:

```bash
php tools/evaluate-search.php --experiments
php tools/evaluate-search.php --experiments --dataset=/absolute/path/relevance.json --json
```

The extra profiles isolate these hypotheses:

- `rarity`: the actual opt-in Finder output, computed directly rather than by
  adjusting frozen native scores;

- `smooth_tf`: replace only the native repeat bonus with `2*tf/(1+tf)`, retaining
  the hard coverage/exact-form ordering;
- `idf_hard`: replace only the native frequency-reduction factor with positive
  BM25-style IDF, retaining the hard coverage/exact-form ordering;
- `idf_soft`: use IDF plus a soft significant-term coverage penalty instead of the
  lexicographic coverage veto;
- `idf_smooth`: combine IDF, soft coverage and the smooth repeat bonus;
- `idf_smooth_c4` / `idf_smooth_c8`: stronger coverage penalties with powers 4 / 8,
  compared to power 2 in `idf_soft` / `idf_smooth`.

IDF is `log(1 + (N-df+0.5)/(df+0.5))`, normalized to weight 1 at `df=1`.
Native field coefficients, entry-size weighting, external relevance ratios and
proximity geometry are retained. Proximity frequency factors follow the same IDF
change. Exact-form postings do not add duplicate term-frequency contributions.

Soft coverage uses the sum of IDF weights for matched significant query groups
divided by the sum for all groups, raised to the profile's power. Group document
frequency is the union across all alternative lemma/exact keys, so ambiguity does
not count as multiple query terms. Multiword exactness becomes a small score bonus;
single-term exactness remains a hard priority. Required phrases stay hard filters.

These are arithmetic adjustments to the same native candidates, not a second
index or another library. Full rankings, including matches below the first page,
are retained. Dates and external IDs keep deterministic tie-breaking. No snippets,
schema migrations, index rebuilds, model downloads, external API calls or public
configuration changes are introduced. Tuning must use development judgments;
report the held-out queries and individual regressions separately.

## Evaluating an installation's queries

Supply a separate corpus and manually judged queries:

```bash
php tools/evaluate-search.php --dataset=/absolute/path/relevance.json --json
```

The JSON format is:

```json
{
  "documents": [
    {"id": "theatre", "title": "Histories of theatres", "content": "Stage performances over time.", "keywords": "drama"},
    {"id": "bicycle", "title": "History of bicycles", "content": "Urban cycling."}
  ],
  "queries": [
    {"query": "history theatre", "relevance": {"theatre": 3}}
  ]
}
```

Document IDs must be unique, keywords are optional, and judgments must refer to corpus documents.
Every query needs at least one document graded 2 or 3. Include difficult and ordinary queries,
short and long articles, and several relevant results where appropriate. Keep a separate set of
queries for final evaluation when tuning BM25F parameters. Site-specific corpora and judgments
belong to that installation rather than the product's generic regression fixtures.
