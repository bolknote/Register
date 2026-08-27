# Asset provenance

Register does not copy visual assets from Aegea. Product-interface symbols should be Unicode text,
emoji, or original artwork. Emoji used as quiet monochrome controls are rendered with
`filter: grayscale(1)` by the Register theme. The administration link uses the Unicode `ℜ` glyph,
not an imported image.

Current product assets:

- `_styles/register/favicon.svg`, `.png`, and `.ico` are original Register artwork created from the
  user's supplied low-resolution **R / Register** reference; the SVG is the source of the raster
  variants.
- `_styles/pixel-forest/forest-header.svg` and `favicon.svg` are original, repository-native pixel
  artwork created for the Pixel Forest theme. They have no external source material or bundled font.
- `_include/src/Register/Module/Analytics/resources/counter-pattern.png` is inherited from the S2
  repository (`_extensions/s2_counter/pattern.png`) and remains byte-for-byte identical.
- `_admin/i/*` are inherited S2 administration assets and predate Register.
- Highstock is third-party software distributed under its own terms and is not Register artwork.
- `_assets/register/math/vendor/katex` contains the official KaTeX 0.18.4 browser distribution and
  fonts under the MIT license. Its source release, checksum, and license are recorded beside the files.
- `_assets/register/syntax-highlighting/vendor/highlight.js` contains an official Highlight.js
  11.11.2 custom browser build under the BSD 3-Clause license. It combines the common set with eight
  explicitly selected languages. Its source tag, commit, checksum, complete language manifest, and
  license are recorded beside the bundle.
- `_assets/register/audio-player` contains an original native-audio implementation whose compact
  visual presentation is inspired by Jouele. No Jouele JavaScript, CSS, or artwork is bundled. The
  original authors' complete MIT notice and source link are recorded beside the player.
- `_assets/register/image-optimizer/lib/register-webp.js` and `register-webp.wasm` are a reproducible Emscripten build of
  libwebp 1.6.0 from the official WebM release archive. The pinned source checksum, build flags,
  thin MIT-licensed wrapper, and rebuild command live under `tools/image/`; libwebp's BSD license is
  bundled as `_assets/register/image-optimizer/lib/register-webp.LICENSE`.
- `_assets/register/image-optimizer/lib/register-resize.js` and `register-resize.wasm` are the resize codec from
  `@jsquash/resize` 2.1.1, pinned by its npm archive SHA-256. The editor uses its Lanczos3,
  premultiplied-alpha, linear-sRGB path; the Apache 2.0 package license and underlying MIT codec
  license are bundled beside the files, and `tools/image/update-resize-wasm.sh` reproduces them.
- Reaction emoji are Unicode characters rendered by the visitor's platform; no Facebook or Telegram
  artwork is bundled.

When adding a binary or SVG asset, record its origin and license here. A visual resemblance is not
enough provenance: do not import artwork from a reference product merely because its interface is
being studied.
