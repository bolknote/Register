# URL slug generation

Register creates the initial slug when a post is created and then keeps it stable. Editing the
title does not silently change an existing permalink; the slug remains editable in the post form.

The generation pipeline is:

1. When PHP's optional `intl` extension is present, ICU transliterates the title with
   `Any-Latin; Latin-ASCII; Lower()`.
2. If ICU is unavailable or leaves non-ASCII characters, `voku/portable-ascii` provides the
   deterministic pure-PHP fallback. The fallback does not need `intl`, `iconv`, or `mbstring`.
3. Everything except lowercase ASCII letters and digits becomes a hyphen. Repeated separators and
   leading or trailing hyphens are removed.
4. A collision receives the first available numeric suffix: `post`, `post-2`, `post-3`, and so on.
5. A title without any transliterable characters falls back to `post` before collision handling.

`ext-intl` is a Composer suggestion, not a platform requirement. `symfony/polyfill-iconv` satisfies
the inherited mbstring polyfill on hosts without native iconv; URL transliteration itself never
calls iconv.
