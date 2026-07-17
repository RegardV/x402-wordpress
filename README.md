# x402 for WordPress

**by [realandworks.com](https://realandworks.com)**

Sell files, endpoints, and answers to **AI agents** for USDC, straight from WordPress, over the [x402](https://x402.org) payment protocol. Self-custodial: payments settle on-chain **directly to your wallet** — no account with anyone, no middleman, **0% fees taken by this plugin**.

Sibling projects: [x402-sandbox](https://github.com/RegardV/x402-sandbox) (self-hosted gateway) · [x402-packager](https://github.com/RegardV/x402-packager) (paid retrieval over a markdown corpus).

## How a sale works

```
Agent                        WordPress                        Facilitator
  │ GET /x402/v1/demo            │                                 │
  │◀── 402 + payment-required (base64 challenge, no-store)         │
  │ retry + payment-signature    │                                 │
  │                              ├── POST /verify ────────────────▶│
  │                              ├── POST /settle ────────────────▶│  USDC → your wallet
  │                              ├── ledger (UNIQUE tx_hash)       │
  │◀── 200 + PAYMENT-RESPONSE receipt + content ──                 │
```

No cryptography runs in WordPress and **no private keys ever exist in WordPress** — the facilitator verifies signatures and settles on-chain; the plugin is careful HTTP/JSON.

## Status: Tier 0 — protocol proof (working)

One hardcoded demo product, sold end-to-end on Base Sepolia testnet: real purchases settled on-chain against this code by an independent x402 client. Current wire format (v2, SDK ≥2.18 `payment-signature` header with `X-PAYMENT` fallback), CDP description-length cap, and cache hardening (`no-store` + `DONOTCACHEPAGE`) are already baked in.

Roadmap (spec'd): per-post/page/attachment products via meta box, machine catalog, request-funnel analytics, settings UI (separate testnet/mainnet wallet channels), then the flagship **paid `/ask` lane** — upload a markdown vault, agents pay per question and get cited passages. WooCommerce bridge and human checkout are deliberate non-goals for the MVP.

## Try it

```bash
docker compose -p x402wp up -d
docker exec x402wp-cli-1 wp core install --url=http://localhost:8890 --title=x402dev \
  --admin_user=admin --admin_password=admin --admin_email=dev@example.com --skip-email
docker exec x402wp-cli-1 wp plugin activate x402-for-wordpress
docker exec x402wp-cli-1 wp option update x402_pay_to 0xYourReceiveAddress

curl -sD - "http://localhost:8890/?rest_route=/x402/v1/demo"   # → 402 + challenge
```

Pay it with any x402 client (e.g. the sandbox's `scripts/buy.mjs` with a funded Base Sepolia wallet) and you get the content plus a `PAYMENT-RESPONSE` receipt.

## Tests

```bash
php composer.phar install
./vendor/bin/phpunit        # unit tests against wire fixtures captured from production
```

The protocol classes (`includes/`) are WordPress-free and tested against real captured challenges and signed payments. Conformance testing uses a real paying client against the live testnet facilitator.

## License

GPL-2.0-or-later
