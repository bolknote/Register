# Search relevance

Register ranks results by the number of matched significant query terms, then exact source forms,
then the existing relevance score. Matches may span the title, keywords and body. Each input term
counts once even when the Russian dictionary supplies several alternative lemmas, or when a word
appears in several fields. This ordering is applied before pagination.

Common English and Russian articles, prepositions and conjunctions do not determine coverage or
exactness when the query contains other words. They remain indexed, searchable and highlighted;
connector-only queries retain their original behavior. Negation is not discarded.

No index-format change or rebuild is required for this ranking change. Existing exact-form and
lemma postings already supply the necessary information. The result trace includes
`matchedQueryTerms` and `exactQueryTerms` alongside the numerical relevance score.

## Reproducible comparisons

Run the offline comparison with:

```bash
php tools/evaluate-search.php
php tools/evaluate-search.php --json
```

The bundled dataset contains 38 synthetic documents and 40 Russian/English queries. It covers
inflections, ambiguous morphology, multiword coverage, matches across fields, connectors,
historical spelling and keyword matches. It contains no production article identifiers or
production query logs, and its scores are not an estimate of live search quality.

The evaluator builds a separate SQLite index in memory and compares:

- `legacy`: the previous exact-count-first comparator over the same postings and relevance scores;
- `coverage`: the actual current Finder output;
- `bm25f`: an offline prototype that keeps coverage and exactness priorities, replacing the remaining
  relevance score with BM25F (`k1=1.2`, `b=0.75`, title/keyword/body weights `5/3/1`).

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
