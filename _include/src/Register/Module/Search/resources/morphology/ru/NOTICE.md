# Russian morphology data

This directory contains a search-oriented subset of
[`pymorphy3-dicts-ru` 2.4.417150.4580142](https://pypi.org/project/pymorphy3-dicts-ru/):

- `meta.json`
- `paradigms.array`
- `suffixes.json`
- `words.dawg`

The compiled data comes from the [OpenCorpora Russian dictionary](https://opencorpora.org/),
revision 417150 (391,778 source lexemes), compiled on 2022-01-08. The four files are
redistributed unchanged; only files not needed by Register's search-only analyzer were omitted.

The dictionary data is licensed under the
[Creative Commons Attribution-ShareAlike 3.0 Unported license](https://creativecommons.org/licenses/by-sa/3.0/).
Attribution belongs to the OpenCorpora contributors and the maintainers of
[`pymorphy3-dicts`](https://github.com/no-plagiarism/pymorphy3-dicts).

Register's PHP reader is MIT-licensed application code. Its compatible decoding of the pymorphy
dictionary and DAWG formats was informed by the MIT-licensed
[`pymorphy3`](https://github.com/no-plagiarism/pymorphy3) and
[`DAWG-Python`](https://github.com/pytries/DAWG-Python) projects.
