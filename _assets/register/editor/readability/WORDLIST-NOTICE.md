# Russian word frequency data

The data in ru-common.js comes from [wordfreq 3.1.1](https://pypi.org/project/wordfreq/3.1.1/)
by Robyn Speer and is distributed under [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/).
The [wordfreq attribution and source notices](https://github.com/rspeer/wordfreq/blob/master/NOTICE.md)
credit Google Books Ngrams, Wikipedia contributors, the Leeds Internet Corpus,
ParaCrawl and [OpenSubtitles](https://www.opensubtitles.org/) via
[OPUS](https://opus.nlpl.eu/OpenSubtitles.php), as well as the project's other data sources.

This adaptation uses the first 5,000 unique alphabetic Russian word forms from
the frequency buckets of wordfreq/data/small_ru.msgpack.gz. The forms are folded
from ё to е before deduplication and sorted alphabetically for packaging. The
verified wheel has SHA-256
4b1c6ecffc6198be3396d5cf871c4423ca71c907c231348d352dd54d62b97473.
