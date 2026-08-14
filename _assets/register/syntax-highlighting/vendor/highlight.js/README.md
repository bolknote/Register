# Highlight.js in Register

Register ships a local custom build of Highlight.js 11.11.2 from the official
`11.11.2` tag (commit `f273f007f85e2096de41c42bf40b870dcb5a5a05`). It was built with:

```console
npm ci --ignore-scripts --no-audit --no-fund
node tools/build.js -t browser :common
```

The minified bundle SHA-256 is
`62960a35954a685dbe12958092f661a185231e9f5f5c44dc3c1e237d9e087d5a`.
Its BSD 3-Clause license is in `LICENSE` next to this file.

The official `:common` build contains Bash, C, C++, C#, CSS, diff, Go, GraphQL,
INI, Java, JavaScript, JSON, Kotlin, Less, Lua, Makefile, Markdown, Objective-C,
Perl, PHP, PHP templates, plaintext, Python, Python REPL, R, Ruby, Rust, SCSS,
shell, SQL, Swift, TypeScript, VB.NET, WebAssembly, XML, and YAML.

Additional language definitions and Highlight.js plugins can remain separate.
Register them without changing the common bundle:

```js
RegisterSyntaxHighlighting.use(function (hljs) {
    hljs.registerLanguage('my-language', myLanguageDefinition);
    hljs.addPlugin(myHighlightPlugin);
});
```

The callback runs as soon as Highlight.js is available. If the page was already
highlighted, Register repeats highlighting with the new language or plugin.
Dynamic content can be processed with
`RegisterSyntaxHighlighting.highlight(element)`; an existing subtree can be
processed again with `RegisterSyntaxHighlighting.refresh(element)`.

Source: <https://github.com/highlightjs/highlight.js/tree/11.11.2>
