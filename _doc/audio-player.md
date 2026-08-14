# Audio player

The audio player is a Register base module. It progressively enhances native HTML audio on public
pages and in the editor preview. It uses the browser's own media engine and has no jQuery, Howler,
or remote-service dependency.

Use semantic HTML in a page or post:

```html
<audio controls preload="metadata" src="/_pictures/interview.mp3" data-title="Interview with the author"></audio>
```

Multiple sources work as usual when format fallback is useful:

```html
<audio controls preload="metadata" data-title="Field recording">
    <source src="/_pictures/field-recording.ogg" type="audio/ogg">
    <source src="/_pictures/field-recording.mp3" type="audio/mpeg">
</audio>
```

In the editor, open **Media**, upload or select an MP3, WAV, Ogg, or FLAC file, and choose **Insert**.
Register writes the native fallback markup with `controls`, `preload="metadata"`, and a title derived
from the filename; it appears as the custom player in the live preview.

`data-title` is optional. Without it, Register derives a readable title from the source filename.
Add `data-register-audio-native` to keep the browser's standard controls for an individual element.

The native `controls` attribute is deliberate. Register removes it only after the player stylesheet
has loaded and the custom controls have initialized. If JavaScript or the stylesheet fails, the
browser player remains available. The timeline is a native range input, so arrow keys and assistive
technologies work without custom keyboard emulation.

Only the small detector is present on ordinary pages. The player implementation and stylesheet are
requested when a page actually contains an eligible `<audio>` element. Audio added by the live
editor preview is detected without reloading the preview frame. Starting one Register player pauses
the previously active one.

Actual codec support is determined by the visitor's browser. MP3 and WAV are the safest single-file
choices; use multiple `<source>` elements if a deployment needs broader fallback. Production web
servers should support HTTP byte-range requests so seeking does not require downloading the whole
recording first.

The compact visual presentation is inspired by Jouele, while the implementation is original and
built directly on `<audio>`. Jouele's complete MIT notice is distributed in
[`_assets/register/audio-player/THIRD_PARTY_NOTICES.md`](../_assets/register/audio-player/THIRD_PARTY_NOTICES.md).
