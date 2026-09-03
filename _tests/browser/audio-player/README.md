# Native audio browser regression check

Run `node _tests/browser/audio-player/server.mjs` from the repository and open
`http://127.0.0.1:8081/` in Opera. `AUDIO_TEST_PORT` can select another loopback port.
The server only exposes this fixture and the three actual player assets. It generates a silent
one-hour WAV in memory, handles HTTP byte ranges, and throttles every audio response. No database,
production request, external audio file, or additional dependency is involved.

Check on desktop and a narrow mobile viewport, in both themes:

1. Reload. Click **59:00** before any audio has loaded. It should request playback immediately,
   show a pause control, and move near 3540 seconds after metadata arrives. The HTTP log must show
   a `206` request starting far inside the file, with much less than 28.8 MB sent first.
2. Reload. Click **12:35**, then **59:00** before the four-second metadata delay ends. The final
   position must be near 3540, not 755. Pause during the delay; metadata must not restart playback.
3. Seek between the beginning and the end. Compare the individual SVG buffer segments with the
   visible `buffered` ranges. The large unloaded middle must not appear buffered.
4. Drag the slider back and forth while playing. It must not jump backwards under the pointer.
   Focus it and use arrow keys; keyboard seeking must remain native and functional.
5. Start the second recording while the first is loading or playing. Only the second may play.
6. Remove the first player while loading/playing. Its retained test-state entry must say
   `connected: false`, `paused: true`; no late metadata event may resume it. Restore it and repeat.
7. Type into the notes field, including spaces. Playback must not intercept text input.
8. Check the error output and browser console. No script/CSP errors or unhandled rejections.

The deterministic state-machine cases also run with `node --test _tests/javascript/audio-player.test.mjs`.

Verified in Opera on localhost, 2026-09-03: desktop, 390 px and 320 px viewports, light/dark themes.
The 59-minute jump downloaded 232,000 initial bytes, then requested `bytes=28311552-` with status
206 instead of downloading the preceding 28 MB. Repeated timestamps, pause during loading,
disconnected loading/playing elements, switching players, mouse/keyboard seeking and ordinary
text input were checked against the native media state. The two separate buffered ranges remained
separate SVG segments; no horizontal overflow appeared at the narrow widths.
