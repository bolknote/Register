# Highlight.js in Register

Register ships a local custom build of Highlight.js 11.11.2 from the official
`11.11.2` tag (commit `f273f007f85e2096de41c42bf40b870dcb5a5a05`). It was built with:

```console
npm ci --ignore-scripts --no-audit --no-fund
node tools/build.js -t browser :common applescript basic brainfuck delphi dos fortran lisp powershell vbscript x86asm
```

The minified bundle SHA-256 is
`7d7d1587f28ba97e454732ad81fd23fed193fa034468c93789cd15eeb4450c14`.
Its BSD 3-Clause license is in `LICENSE` next to this file.

The official `:common` build contains Bash, C, C++, C#, CSS, diff, Go, GraphQL,
INI, Java, JavaScript, JSON, Kotlin, Less, Lua, Makefile, Markdown, Objective-C,
Perl, PHP, PHP templates, plaintext, Python, Python REPL, R, Ruby, Rust, SCSS,
shell, SQL, Swift, TypeScript, VB.NET, WebAssembly, XML, and YAML.
Register adds AppleScript, BASIC, Brainfuck, Delphi, DOS batch files, Fortran,
Lisp, PowerShell, VBScript, and Intel x86 assembly to that set, for 46 languages in total.
The complete machine-readable list and reproducible build metadata are in
`languages.json`.

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
