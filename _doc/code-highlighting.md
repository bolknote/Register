# Code highlighting

Syntax highlighting is a Register base module. It is present in every installation and cannot be
disabled. Register uses a local custom build of Highlight.js; no page content or code is sent to a
third party.

Use semantic HTML for new code blocks. Without a language class Highlight.js detects a language
from its common set:

```html
<pre><code>SELECT title FROM content WHERE is_published = 1;</code></pre>
```

Specify a language when it is known to avoid detection mistakes:

```html
<pre><code class="language-php">echo 'Hello';</code></pre>
```

The inherited bare `<pre>...</pre>` form is still recognized so imported posts render correctly.
Add `nohighlight` or `no-highlight` to the `<code>` or `<pre>` class list when a block must stay
plain.

Only the small loader is present on ordinary pages. The local theme and the 36-language common
bundle are requested when the page contains a code block. The theme follows Register's light and
dark palettes. The exact Highlight.js release, checksum, license, build command, and language list
are recorded in
[`_assets/register/syntax-highlighting/vendor/highlight.js/README.md`](../_assets/register/syntax-highlighting/vendor/highlight.js/README.md).

## Additional languages and plugins

Keep less common languages and Highlight.js plugins in separate assets. Register them through the
public loader API rather than rebuilding the common bundle:

```js
RegisterSyntaxHighlighting.use(function (hljs) {
    hljs.registerLanguage('my-language', myLanguageDefinition);
    hljs.addPlugin(myHighlightPlugin);
});
```

The callback is queued until Highlight.js is available. If highlighting already happened, Register
reprocesses existing blocks after installing the extension. Code added to the page later can be
processed explicitly:

```js
RegisterSyntaxHighlighting.highlight(container);
```
