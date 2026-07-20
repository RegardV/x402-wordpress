# The "reverse proxy" setting

**Network tab → Reverse proxy checkbox.** One tick, real security consequences. This page explains exactly what it does and how to set it correctly.

## What it controls

The plugin needs to identify a visitor by IP address for two things:

1. **Rate limiting** — capping how many unpaid requests one source can make to `/ask` (anti-abuse).
2. **Redelivery grants** — when a buyer's download fails *after* they paid, their next request within the window is served **free** (never charged twice). "Same buyer" is decided by IP.

The question is: **which value does the plugin trust as the client's IP?**

- **Unchecked (default):** it uses PHP's `REMOTE_ADDR` — the address of whoever actually opened the TCP connection to your server. This cannot be forged by the client.
- **Checked:** it uses the first entry in the `X-Forwarded-For` (XFF) HTTP header instead.

## Why this matters

`X-Forwarded-For` is **just a header the client sends** — anyone can put any value in it. It is only trustworthy when a proxy *you control* sits in front of your site, strips any inbound XFF, and sets its own with the real client IP.

- If your site sits **behind Cloudflare, an nginx reverse proxy, a load balancer, or a CDN** that terminates the connection and forwards to WordPress, then `REMOTE_ADDR` is the *proxy's* IP (the same for every visitor), and the real client IP is in `X-Forwarded-For`. **Tick the box** — otherwise every visitor looks like one IP and rate limiting/redelivery misbehave.
- If visitors connect **directly to your PHP/web server** (no proxy in front), `REMOTE_ADDR` is already the real client IP. **Leave the box unchecked** — trusting XFF here lets any client forge it.

## The risk of getting it wrong

Ticking the box **without** a trusted proxy that strips inbound XFF means the client controls the value the plugin trusts. Concretely:

- **Rate-limit bypass:** a client rotates a fake `X-Forwarded-For` on every request and never hits the limit.
- **Free redelivery to a stranger:** if an attacker can send the same `X-Forwarded-For` value a real buyer used (e.g. they know or can guess it), they get free re-delivery of that buyer's paid content for the redelivery window.

Neither takes money from you, but both give away access you meant to charge for. When in doubt, **leave it off** — the failure mode of "off behind a proxy" (everyone shares the proxy IP) is annoying but safe; the failure mode of "on without a proxy" is exploitable.

## How to tell which applies

- Using **Cloudflare**, a managed host with a load balancer, or a hand-rolled **nginx/Apache reverse proxy** in front of PHP → **on**.
- **WP Engine, Kinsta, most managed WordPress hosts** → usually behind a proxy → **on** (check their docs for whether they set `X-Forwarded-For`).
- A plain VPS where Apache/PHP faces the internet directly → **off**.
- Not sure? Compare: if `REMOTE_ADDR` is a private/internal address (`10.x`, `172.16–31.x`, `192.168.x`) or the same for all visitors, there's a proxy → **on**. If it's the visitor's real public IP → **off**.

## A note for local / Docker development

Some stacks (including the official WordPress Docker image and "Local by Flywheel") run Apache's `mod_remoteip`, which **rewrites `REMOTE_ADDR` from `X-Forwarded-For` at the web-server layer** before PHP ever sees it. On such a stack the plugin's own setting has no effect — the rewrite already happened. That's fine for development; just be aware that "it worked locally" doesn't tell you how a bare production host behaves. Set this according to your *production* topology.

## Summary

| Your setup | Setting |
|---|---|
| Behind Cloudflare / nginx / load balancer / managed host that sets XFF | **On** |
| PHP/Apache faces the internet directly | **Off** |
| Unsure | **Off** (safe default) |
