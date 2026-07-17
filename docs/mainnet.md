# Going to mainnet (real USDC)

Testnet needs nothing but a receive address. Mainnet needs a Coinbase Developer Platform key pair — free, and funds still settle directly to *your* wallet (Coinbase never custodies them; the keys only authenticate the settlement call).

## Steps

1. **Get CDP keys** — at [portal.cdp.coinbase.com](https://portal.cdp.coinbase.com), create a **Secret API Key**. You get a key **ID** (`organizations/…/apiKeys/…`) and a **secret** (a base64 Ed25519 string). Copy both; the secret is shown once.
2. **wp-admin → x402:**
   - Paste your **mainnet receive address** into its channel (a wallet you control on Base).
   - Paste the CDP **key ID** and **secret**. The secret is encrypted at rest and never shown again.
   - Switch **Active network** to **Mainnet**.
   - Save.
3. The store-endpoints panel turns green only when a mainnet address **and** working CDP keys are present. Product prices are the same numbers; they now move real USDC.

## First-sale checklist (the sandbox playbook)

- Keep the **first product cheap** ($0.01–$0.05) and buy it yourself once, end to end.
- Descriptions are capped at 250 chars — the CDP facilitator rejects longer ones (undocumented; the plugin caps automatically).
- If a purchase settles but delivery fails, the buyer's next unpaid request within the redelivery window is served free (`x-redelivery` header) — a failed download never charges twice.
- Watch the funnel line on the admin page: "N saw the price, M paid" is your live conversion.

## Escape hatch

`add_filter('x402_facilitator_url', fn() => 'https://your-facilitator');` points settlement at any x402 facilitator (self-hosted, or a hosted convenience one) without touching plugin code — bypasses the CDP path entirely.
