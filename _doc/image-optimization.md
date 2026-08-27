# Automatic editor image optimization

The public inline editor turns every pasted or dropped still image that the browser can decode into
one final JPEG, PNG, or WebP file.
It does not create a separate `1x` file, a `srcset`, or an unused alternative format.

## Storage and historical names

Set the destination relative to `_pictures/` in the deployment configuration:

```php
'files' => [
    'content_image_directory' => '/bolknote/images',
],
```

An empty value keeps post media directly in `_pictures/`. The browser cannot override this
directory or filename: the public upload controller chooses both under an exclusive allocation lock.

The date always comes from the current publication-date field of the note, including a past or future
date. If that field changes after an upload but before saving, pending media are renamed before the
HTML is serialized.

Names retain the historical sequence:

```text
yyyy.mm.dd.ext
yyyy.mm.dd.1.ext
yyyy.mm.dd.2@2x.ext
```

The unnumbered slot is first. Numeric slots are shared across all images and audio files regardless
of extension; only Retina images receive `@2x`. Thus `yyyy.mm.dd.webp` is followed by
`yyyy.mm.dd.1.mp3`, never by a hash or a second unnumbered filename.

## Dimensions and Retina behavior

- A source at least 2000 pixels wide is Retina-ready. It is reduced to 2000 physical pixels when
  wider and inserted at half its physical dimensions. Only this `@2x` file is stored.
- A source narrower than 2000 pixels stays at its natural dimensions and is never enlarged.
- The resize uses Lanczos3 in linear sRGB with premultiplied alpha.
- Inputs above the decoder safety limits are rejected before allocating an unsafe canvas.

## Format selection and quality

The editor compares the formats that are meaningful for the image and uploads the smallest accepted
candidate:

- an unresized original can remain JPEG, PNG, or WebP after private metadata is removed;
- WebP follows the historical `cwebp` policy: quality 82, method 6, autofilter, sharp YUV, and no
  `exact` preservation of invisible RGB below fully transparent pixels;
- JPEG quality is searched from 75 to 95 and must reach SSIM 0.985, or the historical Retina SSIM
  threshold 0.97;
- indexed PNG must reach SSIM 0.98 and PSNR 40 dB;
- lossless WebP and optimized truecolor PNG cover transparency and lossless fallbacks.

`exact` is not a gamma or color-correction option. It only retains RGB samples that cannot be seen
because their alpha is zero. Quality checks compare transparent images on both black and white
backgrounds, so those invisible samples do not distort the decision.

## Color and metadata

Browser decoding applies the embedded color profile and normalizes re-encoded pixels to sRGB before
resizing and comparison. When an already efficient, unresized source wins without re-encoding, its
ICC profile remains embedded. EXIF, XMP, comments, timestamps, and PNG text chunks are stripped from
public output. EXIF orientation in JPEG, PNG, or WebP is applied before re-encoding when it is not
the identity transform.

Animated PNG and animated WebP are rejected rather than silently flattened to the first frame.

## Reproducible codecs and checks

The checked-in WebAssembly binaries are pinned and licensed in [`assets.md`](assets.md). Rebuild or
refresh them with:

```bash
tools/image/build-webp-wasm.sh
tools/image/update-resize-wasm.sh
```

Run the deterministic policy and metadata tests with:

```bash
composer test:javascript
```

The production release builder includes the workers, WebAssembly modules, JavaScript facades, and
their license files in the hashed release manifest.
