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

## Timestamp links

Link to the audio file with a `#t=` fragment to start the player already present on the page at that
time. No special classes, inline scripts, or editor-specific attributes are required:

```html
<audio controls preload="metadata" src="/_pictures/interview.mp3" data-title="Interview"></audio>
<p><a href="/_pictures/interview.mp3#t=12:35">Listen from 12:35</a></p>
```

Seconds (`#t=755`), minutes and seconds (`#t=12:35`), and hours, minutes and seconds
(`#t=1:12:35`) are supported, including fractional seconds and the `npt:` prefix. Use the same file
URL, including query parameters, as the audio or one of its `<source>` elements. When that file
occurs more than once, the player in the same article/comment takes precedence.

The last requested position is remembered until metadata arrives, then applied directly to the
native audio element. Playback is requested during the click, so it does not rely on delayed
autoplay permission. Pausing while the file loads does not restart it when metadata arrives.
Without a matching player (or without JavaScript), the link opens normally. Modified clicks,
downloads, external/new-window links, and links inside the editor keep their usual behavior.
Only point timestamps are intercepted; fragments specifying an end time keep native link behavior.

## Playback and fallback

`data-title` is optional. Without it, Register derives a readable title from the source filename.
Add `data-register-audio-native` to keep the browser's standard controls for an individual element.

The native `controls` attribute is deliberate. Register removes it only after the player stylesheet
has loaded and the custom controls have initialized. If JavaScript or the stylesheet fails, the
browser player remains available. The timeline is a native range input, so arrow keys and assistive
technologies work without custom keyboard emulation.

Only the small detector is present on ordinary pages. The player implementation and stylesheet are
requested when a page actually contains an eligible `<audio>` element. Audio added by the live
editor preview is detected without reloading the preview frame. Starting one Register player pauses
the previously active one, including a recording that is still loading. Removing a player during
partial navigation or editor updates stops it and detaches its listeners. The buffering line shows
the individual downloaded ranges rather than filling gaps left by a seek.

Actual codec support is determined by the visitor's browser. MP3 and WAV are the safest single-file
choices; use multiple `<source>` elements if a deployment needs broader fallback. Production web
servers should support HTTP byte-range requests so seeking does not require downloading the whole
recording first.

For a reproducible Opera/browser check, run `node _tests/browser/audio-player/server.mjs` and open
the loopback URL it prints. This isolated fixture serves the real player assets with a one-hour
silent WAV, throttled byte-range responses, delayed metadata, and visible playback/request logs.
It does not access the blog database or production. See the checklist next to the fixture.

The compact visual presentation is inspired by Jouele, while the implementation is original and
built directly on `<audio>`. Jouele's complete MIT notice is distributed in
[`_assets/register/audio-player/THIRD_PARTY_NOTICES.md`](../_assets/register/audio-player/THIRD_PARTY_NOTICES.md).
