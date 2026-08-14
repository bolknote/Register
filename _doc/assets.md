# Asset provenance

Register does not copy visual assets from Aegea. Product-interface symbols should be Unicode text,
emoji, or original artwork. Emoji used as quiet monochrome controls are rendered with
`filter: grayscale(1)` by the Register theme. The administration link uses the Unicode `ℜ` glyph,
not an imported image.

Current product assets:

- `_styles/register/favicon.svg`, `.png`, and `.ico` are original Register artwork created from the
  user's supplied low-resolution **R / Register** reference; the SVG is the source of the raster
  variants.
- `_include/src/Register/Module/Analytics/resources/counter-pattern.png` is inherited from the S2
  repository (`_extensions/s2_counter/pattern.png`) and remains byte-for-byte identical.
- `_admin/i/*` are inherited S2 administration assets and predate Register.
- Highstock is third-party software distributed under its own terms and is not Register artwork.
- `_assets/register/math/vendor/katex` contains the official KaTeX 0.18.4 browser distribution and
  fonts under the MIT license. Its source release, checksum, and license are recorded beside the files.

When adding a binary or SVG asset, record its origin and license here. A visual resemblance is not
enough provenance: do not import artwork from a reference product merely because its interface is
being studied.
