# Build log

The honest record of building x402 for WordPress: the walls we hit, how we got past them, and what we had to rebuild from scratch because it didn't exist. Written for anyone integrating x402 into a non-JavaScript stack — most of these traps are undocumented and cost real time to find.

The plugin was built in four tiers, each ending with a **conformance gate**: a real, independent x402 client settling a real payment on-chain against the PHP code, on the Base Sepolia testnet. Nothing counted as "working" until money moved.

---

## What we had to reinvent

The entire x402 ecosystem is TypeScript and Go. There is **no PHP SDK**. Everything below the protocol line had to be rebuilt in PHP from the wire format and the reference SDK's source — not ported, reverse-engineered.

### 1. The x402 challenge/receipt wire format
The 402 challenge, the base64 `payment-required` header, the `PAYMENT-RESPONSE` receipt — all rebuilt from captured production traffic rather than a spec, because the spec lags the SDK. We pinned the exact shape by decoding a live challenge from a running store and reproducing it byte-for-byte in a test fixture.

### 2. The facilitator client
The `/verify` → `/settle` HTTP dance, including the exact request body shape (`{x402Version, paymentPayload, paymentRequirements}`), rebuilt by reading the `@x402/core` facilitator source. The merchant never touches cryptography — the facilitator verifies signatures and settles on-chain — so this is a few hundred lines of careful HTTP/JSON, which is exactly why a PHP port is viable at all.

### 3. Coinbase CDP mainnet authentication — the hard one
Mainnet settlement runs through Coinbase's CDP facilitator, which requires a **signed EdDSA JWT** on every call. No PHP library does this. We read `@coinbase/cdp-sdk`'s `generateJwt` line by line and rebuilt it with libsodium:

- Algorithm `EdDSA`, header carries `kid` + a fresh 16-byte hex `nonce`
- Claims: `sub`, `iss: "cdp"`, `uris: ["POST api.cdp.coinbase.com/platform/v2/x402/{verify|settle}"]`, 120-second expiry
- The CDP key secret is a **64-byte base64 Ed25519 key** — which is exactly libsodium's native secret-key layout (32-byte seed + 32-byte public key), so `sodium_crypto_sign_detached` signs it directly

We validated the PHP JWT against Coinbase's **real** `/supported` endpoint before trusting it — HTTP 200, first try, once the claim shape matched.

### 4. Retrieval, chunking, sanitization
The "sell answers, not documents" engine (paid `/ask` over a corpus) is MySQL `FULLTEXT` with BM25-style ranking — no model calls, no API keys. The heading-chunker, the import sanitizer, and the natural-language-to-boolean-query translator were all rebuilt in pure PHP, TDD-first.

---

## The bugs that cost real time

### The header the SDK renamed out from under us
**Symptom:** the very first conformance purchase got a clean 402, signed a payment, retried — and got another 402. The payment was never seen.
**Cause:** we read the payment from the `X-PAYMENT` header, per every piece of documentation. But `@x402/fetch` ≥ 2.18 sends it in **`payment-signature`**. The SDK moved; the docs didn't.
**Fix:** read `payment-signature` first, fall back to `X-PAYMENT`. This same drift existed in our two sibling repos — we swept all three. (In the sandbox it was worse than cosmetic: a current-SDK buyer's paying request looked *unpaid* to the redelivery middleware, silently diverting repeat sales to free re-delivery.)

### The proxy that paid to call itself
**Symptom:** a "proxy product" (resell any upstream URL) charged the buyer, then returned a 402 instead of the upstream's response.
**Cause:** on a WordPress install with plain permalinks, the REST router passes the route through a `rest_route` **query parameter**. Our proxy forwarded the buyer's query string to the upstream — including `rest_route=/x402/v1/p/...`, which pointed the "upstream" call straight back at our own paywall. It recursed into itself.
**Fix:** strip `rest_route` before forwarding. Found only because the conformance client tried to actually buy the thing.

### The sanitizer that un-sanitized itself
**Symptom (caught in review, pre-ship):** an imported note containing the *text* `&lt;script&gt;…&lt;/script&gt;` — a completely ordinary thing to have in a vault of technical notes — could re-materialize as a live `<script>` tag in the paid `/ask` JSON.
**Cause:** the chunker ran `html_entity_decode(strip_tags(...))` — stripping tags *first*, then decoding entities back into tags. The order re-injected what it had just removed.
**Fix:** decode entities **before** stripping tags. Locked with a regression test.

### The buyer who could override the operator's API key
**Symptom (caught in review):** proxy products forward the buyer's query params to the upstream. If an operator configured an upstream with an embedded credential (`?apikey=SECRET`), a buyer could send `?apikey=anything` and `add_query_arg` would overwrite the operator's value.
**Fix:** buyer params that collide with operator-set params are dropped; buyers can add, never override.

### The route that sold your drafts
**Symptom (caught in review, the one that blocked the merge):** the "sell this post" flag, once set, outlived the post's status. A post enabled for sale and then reverted to **draft** — or made **private**, **scheduled**, **trashed**, or **password-protected** — was still fully purchasable and deliverable through `/x402/v1/i/{id}`, and post IDs are trivially enumerable.
**Fix:** the item route now gates on `post_status` (published/inherit only) and rejects password-protected posts. Verified: a draft returns 404, a published post returns the paywall.

### Rate limiting you could spoof away
**Symptom (caught in review):** the per-IP rate limiter trusted `X-Forwarded-For` unconditionally. On a site *not* behind a proxy, any client could rotate that header and bypass the limit entirely.
**Fix:** use `REMOTE_ADDR` by default; honor `X-Forwarded-For` only when the operator explicitly declares a trusted proxy. (The dev Docker image runs Apache `mod_remoteip`, which rewrites `REMOTE_ADDR` from XFF before PHP sees it — a good reminder that "works in dev" and "works on a bare host" are different claims.)

### GitHub blocking our own test fixtures
**Symptom:** the security-fix commit was rejected by GitHub push protection as containing a leaked Slack token.
**Cause:** we'd just written a sanitizer that *rejects* leaked tokens, with test fixtures containing token-shaped strings (`xoxb-…`, `ghp_…`, `AKIA…`). GitHub's scanner and our new feature were looking for exactly the same thing.
**Fix:** concatenate the fixtures (`'xox' . 'b-...'`) so no token-shaped literal exists on any line.

---

## Infrastructure friction (the unglamorous half)

- **`wp-env` never booted** on the build machine — Node's HTTP client timed out downloading WordPress (an IPv6 issue; `curl` to the same host worked fine). Rather than fight it, we swapped to a plain **`docker-compose`** stack with the official `wordpress` image. Same result, no Node downloader in the path.
- **The money path needs to survive cheap hosts.** Two facilitator round-trips (verify + settle) can exceed a 30-second `max_execution_time`. Once `settle()` succeeds the charge is irreversible, so dying before the ledger write or delivery would take money and leave no trace. We raise the time limit, `ignore_user_abort`, and check file readability *before* charging — never take money for content we can't deliver.
- **Caching is where WordPress plugins die.** A cached 402 breaks buying; a cached 200 leaks paid content. Every agent-lane response sends `no-store` and defines `DONOTCACHEPAGE`; verified against WP Super Cache that the paywall is never served from cache.
- **Replayed payments are rejected by the facilitator** (the on-chain nonce is spent), which means a buyer whose download failed *cannot* simply retry their payment. Redelivery had to be handled at the application layer: a prior payer's unpaid re-request within a window is served free (`x-redelivery` header), never re-charged.

---

## What made it tractable

Every tier was gated by a **real paying client against real code** — not mocks. That harness (`buy.mjs` / `buy-post.mjs`, an independent TypeScript x402 client with a funded testnet wallet) caught the `payment-signature` rename, the proxy recursion, and every wire-format assumption that was wrong — in minutes, not in production. It's the single most valuable thing in the build, and the reason a from-scratch PHP implementation of an undocumented, still-moving protocol was safe to attempt at all.

Pure logic (challenge builder, facilitator client, JWT, chunker, sanitizer, search, crypto) is unit-tested TDD-first against fixtures captured from production traffic — **44 tests** at time of writing. Everything that touches WordPress or the chain is proven by conformance purchase. Every tier ended with an adversarial code review before commit; the three issues that would have mattered in production (draft-leak, XSS-via-import, credential-override) were all caught there, not by users.
