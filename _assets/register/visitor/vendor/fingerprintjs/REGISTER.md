# FingerprintJS vendor note

Register ships the browser bundle from `@fingerprintjs/fingerprintjs` 5.2.0.

- Upstream: https://github.com/fingerprintjs/fingerprintjs
- Package: https://www.npmjs.com/package/@fingerprintjs/fingerprintjs/v/5.2.0
- Local bundle: `fp.min.js` (the upstream UMD production build)
- License: MIT; the original notice is preserved in both the bundle header and `LICENSE`

The bundle is self-hosted and initialized with upstream monitoring disabled. Register sends only the
resulting visitor identifier to its own origin, where it is stored as a keyed SHA-256 digest rather
than in raw form.
