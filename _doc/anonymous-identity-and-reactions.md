# Anonymous identity and reactions

Register lets a reader react to a published post or permanent page without creating an account. The
interaction borrows two familiar conventions: Facebook's single active reaction that can be removed
or switched, and Telegram's compact inline emoji counters with a separate reaction palette.

The built-in set is 👍, ❤️, 😂, 😮, 😢, and 😡. One anonymous visitor can hold at most one reaction on
each content item. Clicking the active reaction removes it; choosing another replaces it atomically.
The server renders public counts into the initial HTML, then JavaScript restores the reader's own
selection and updates the interface optimistically.

## Identity recovery

The identity is not a login, credential, or authorization boundary. It consists of a random 128-bit
visitor ID and an HMAC signature made with the installation's private `REGISTER_VISITOR_SECRET`.
Register recovers it in this order:

1. a one-year, same-site visitor cookie;
2. a duplicate signed token in `localStorage` or IndexedDB;
3. a new random visitor ID when none of the previous layers resolves.

Register does not generate or accept a browser fingerprint. Visitor tables likewise contain no raw
IP address or User-Agent. Clearing only cookies does not create a reliably new visitor because the
first-party storage token restores it; clearing all three browser stores starts a new identity.

DNT and Global Privacy Control are deliberately not consulted: they disable neither first-party
identity storage nor aggregate analytics. The identity is useful for statistics and casual duplicate
resistance, not for security or fraud-proof voting.

## Analytics

Page hits are recorded on the original HTML request. If that request has no valid visitor cookie,
the browser completes the same page view after resolving the identity, adding the daily unique count
without adding a second hit. Analytics keeps only an installation-salted, day-scoped derivative of
the visitor ID in its daily unique table and prunes older entries.

## API and storage

- `POST /_visitor/resolve` validates or restores the signed token and refreshes the cookie.
- `GET /_reactions/{page|post}/{id}` returns counts and the current visitor's selection.
- `POST /_reactions/{page|post}/{id}` toggles or switches a reaction.
- `register_visitor` stores random visitor IDs and timestamps.
- `register_reaction` enforces one row per content item and visitor with a composite primary key.

Mutation endpoints accept same-origin JSON only. The visitor script uses browser-native first-party
storage and does not load a fingerprinting library or any third-party resource.
