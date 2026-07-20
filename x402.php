<?php
/**
 * Plugin Name: x402 for WordPress
 * Description: Sell files, endpoints, and answers to AI agents for USDC over the x402 protocol. Self-custodial, 0% fees.
 * Version: 0.2.0
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: x402
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-challenge.php';
require_once __DIR__ . '/includes/class-price.php';
require_once __DIR__ . '/includes/class-facilitator.php';
require_once __DIR__ . '/includes/class-settlements.php';
require_once __DIR__ . '/includes/class-address.php';
require_once __DIR__ . '/includes/class-chunker.php';
require_once __DIR__ . '/includes/class-sanitizer.php';
require_once __DIR__ . '/includes/class-search.php';
require_once __DIR__ . '/includes/class-cdp-jwt.php';
require_once __DIR__ . '/includes/class-crypto.php';
require_once __DIR__ . '/includes/class-setup.php';
require_once __DIR__ . '/includes/class-collections.php';
require_once __DIR__ . '/includes/indexer.php';
require_once __DIR__ . '/includes/products.php';
require_once __DIR__ . '/includes/wizard.php';
require_once __DIR__ . '/includes/admin-page.php';

use X402\Challenge;
use X402\Facilitator;
use X402\Price;
use X402\Search;
use X402\Settlements;

const X402_TESTNET_FACILITATOR = 'https://x402.org/facilitator';
const X402_RESERVED_SKUS       = ['demo', 'ask', 'catalog'];

register_activation_hook(__FILE__, 'x402_upgrade_db'); // idempotent: no-op once at current version

/* ---------- products ---------- */

function x402_demo_product(): array
{
    return [
        'sku'          => 'demo',
        'url'          => rest_url('x402/v1/demo'),
        'description'  => 'x402 for WordPress demo product — a sample markdown file sold over the x402 protocol.',
        'mime_type'    => 'text/markdown',
        'amount_micro' => Price::toMicro('$0.01'),
        'network'      => x402_active_network(),
        'pay_to'       => x402_pay_to(),
        'file'         => __DIR__ . '/sample-content.md',
    ];
}

/** Ask-product descriptor for one collection, or null if it isn't a valid priced collection. */
function x402_ask_product(string $slug): ?array
{
    $c = x402_collection($slug);
    if ($c === null) {
        return null;
    }
    try {
        $amount = Price::toMicro((string) $c['price']);
    } catch (InvalidArgumentException) {
        return null;
    }
    return [
        'sku'          => 'ask:' . $slug,
        'collection'   => $slug,
        'title'        => (string) ($c['title'] ?? $slug),
        'url'          => rest_url('x402/v1/ask/' . $slug),
        'description'  => (string) $c['description'],
        'mime_type'    => 'application/json',
        'amount_micro' => $amount,
        'price'        => (string) $c['price'],
        'network'      => x402_active_network(),
        'pay_to'       => x402_pay_to(),
    ];
}

/** Enabled collections that have a valid price, as ask products. */
function x402_enabled_ask_products(): array
{
    $out = [];
    foreach (x402_collections() as $slug => $c) {
        if (!empty($c['enabled']) && ($p = x402_ask_product((string) $slug))) {
            $out[] = $p;
        }
    }
    return $out;
}

/** Paid retrieval over one collection: validate, rate-limit, paywall, search WHERE collection = slug. */
function x402_ask_collection(string $slug, WP_REST_Request $request)
{
    x402_no_store();
    $product = x402_ask_product($slug);
    if ($product === null || empty(x402_collection($slug)['enabled'])) {
        return new WP_REST_Response(['error' => 'unknown or disabled collection'], 404);
    }
    if (!x402_rate_limit(x402_client_ip_hash(), 30, 60)) {
        return new WP_REST_Response(['error' => 'rate limit exceeded'], 429);
    }

    $body  = $request->get_json_params();
    $query = is_array($body) ? trim((string) ($body['query'] ?? '')) : '';
    $top_k = is_array($body) ? min(10, max(1, (int) ($body['top_k'] ?? 5))) : 5;
    if ($query === '' || mb_strlen($query) > 500) {
        return new WP_REST_Response(['error' => 'body must be JSON {"query": "1..500 chars", "top_k"?: 1..10}'], 400);
    }
    $boolean = Search::boolean_query($query);
    if ($boolean === '') {
        return new WP_REST_Response(['error' => 'query has no searchable terms'], 400);
    }

    global $wpdb;
    if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}x402_chunks WHERE collection = %s", $slug)) === 0) {
        return new WP_REST_Response(['error' => 'nothing indexed in this collection yet'], 503);
    }

    return x402_paywall($product, $request, function (array $settled) use ($wpdb, $slug, $query, $boolean, $top_k) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT source, source_id, source_name, heading, content,
                    MATCH(heading, content) AGAINST(%s IN BOOLEAN MODE) AS score
             FROM {$wpdb->prefix}x402_chunks
             WHERE collection = %s AND MATCH(heading, content) AGAINST(%s IN BOOLEAN MODE)
             ORDER BY score DESC LIMIT %d",
            $boolean,
            $slug,
            $boolean,
            $top_k
        ), ARRAY_A) ?: [];

        $results = array_map(static function (array $r): array {
            $cite = $r['source'] === 'post'
                ? (get_permalink((int) $r['source_id']) ?: $r['source_name'])
                : $r['source_name'];
            return [
                'excerpt' => mb_substr($r['content'], 0, 700),
                'heading' => $r['heading'],
                'source'  => $cite,
                'score'   => round((float) $r['score'], 4),
            ];
        }, $rows);

        $response = new WP_REST_Response(['collection' => $slug, 'query' => $query, 'count' => count($results), 'results' => $results], 200);
        $response->header('PAYMENT-RESPONSE', Facilitator::receipt_header($settled));
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    });
}

/** @return array<array{sku:string, title:string, price:string, url:string}> operator-entered only (SSRF: never user-influenced) */
function x402_proxy_products(): array
{
    $list = get_option('x402_proxy_products', []);
    return is_array($list) ? $list : [];
}

/* ---------- shared plumbing ---------- */

function x402_no_store(): void
{
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    header('Cache-Control: no-store, private');
}

function x402_402_response(array $product): WP_REST_Response
{
    $response = new WP_REST_Response(Challenge::build($product), 402);
    $response->header('payment-required', Challenge::header($product));
    return $response;
}

function x402_client_ip_hash(): string
{
    // X-Forwarded-For is client-forgeable; honor it only when the operator says
    // a trusted proxy sets it, else a spoofed header defeats rate limiting.
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (get_option('x402_trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return hash_hmac('sha256', $ip, wp_salt('auth'));
}

/** Fixed-window counter via an atomic options-table upsert (transients race under bursts). True = allowed. */
function x402_rate_limit(string $bucket, int $limit, int $window_seconds): bool
{
    global $wpdb;
    $window = (int) floor(time() / $window_seconds);
    $key    = 'x402_rl_' . substr($bucket, 0, 32) . '_' . $window;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
         VALUES (%s, '1', 'no')
         ON DUPLICATE KEY UPDATE option_value = option_value + 1",
        $key
    ));
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        $key
    ));
    if (random_int(1, 100) === 1) { // lazy stale-bucket cleanup
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
            $wpdb->esc_like('x402_rl_') . '%',
            '%\_' . $window
        ));
    }
    return $count <= $limit;
}

/** Funnel log: one row per outcome (unpaid_402/paid_200/free_200/error). */
function x402_log_request(string $product_ref, string $outcome): void
{
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'x402_requests', [
        'product_ref' => substr($product_ref, 0, 191),
        'outcome'     => $outcome,
        'ip_hash'     => x402_client_ip_hash(),
        'created_at'  => gmdate('Y-m-d H:i:s'),
    ]);
}

/**
 * The payment wall: 402 challenge → verify → settle → ledger → $deliver($settled).
 * Before charging, an unpaid request from a source that already paid for this
 * product within the redelivery window is delivered free (facilitator rejects
 * replayed payments, so a failed download must be re-served here, not re-paid).
 * $deliver returns the WP_REST_Response for the buyer (or emits raw bytes and exits).
 */
function x402_paywall(array $product, WP_REST_Request $request, callable $deliver)
{
    x402_no_store();
    if ($product['pay_to'] === '') {
        return new WP_REST_Response(['error' => 'x402 receive wallet not configured'], 503);
    }

    global $wpdb;
    $settlements = new Settlements($wpdb);
    $ip_hash     = x402_client_ip_hash();

    $payment = Facilitator::decode_payment(
        $request->get_header('payment-signature') ?? $request->get_header('x-payment')
    );
    if ($payment === null) {
        $window = (int) get_option('x402_redelivery_minutes', 60);
        if ($window > 0 && $settlements->find_redelivery_grant($product['sku'], $ip_hash, $window)) {
            x402_log_request($product['sku'], 'free_200');
            header('x-redelivery: 1');
            return $deliver(['ok' => true, 'tx' => '', 'payer' => '', 'network' => $product['network']]);
        }
        x402_log_request($product['sku'], 'unpaid_402');
        return x402_402_response($product);
    }

    // Two facilitator round-trips must survive hosts with max_execution_time=30:
    // once settle() succeeds the charge is irreversible, so dying before the
    // ledger write or delivery would take money without a trace.
    if (function_exists('set_time_limit')) {
        @set_time_limit(150);
    }
    ignore_user_abort(true);

    $requirements = Challenge::build($product)['accepts'][0];
    $facilitator  = x402_facilitator();

    $verified = $facilitator->verify($payment, $requirements);
    if (!$verified['ok']) {
        error_log('[x402] verify failed (' . $product['sku'] . '): ' . $verified['error']);
        x402_log_request($product['sku'], 'error');
        return x402_402_response($product);
    }

    $settled = $facilitator->settle($payment, $requirements);
    if (!$settled['ok']) {
        error_log('[x402] settle failed (' . $product['sku'] . '): ' . $settled['error']);
        x402_log_request($product['sku'], 'error');
        return x402_402_response($product);
    }

    // Duplicate tx = redelivery of an already-paid purchase, never a recharge.
    if ($settled['tx'] !== '') {
        $settlements->record_once($product['sku'], $product['amount_micro'], $settled, $ip_hash);
    } else {
        error_log('[x402] settled without transaction hash — ledger row skipped: ' . (string) json_encode($settled['raw']));
    }

    x402_log_request($product['sku'], 'paid_200');
    return $deliver($settled);
}

/** 30-day retention on the funnel log. */
add_action('x402_daily', function (): void {
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}x402_requests WHERE created_at < %s",
        gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)
    ));
});
add_action('init', function (): void {
    if (!wp_next_scheduled('x402_daily')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'x402_daily');
    }
});

/** Raw-bytes delivery outside the REST JSON pipeline (files, proxied upstreams). */
function x402_emit_raw(array $settled, int $status, string $content_type, string $bytes): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    status_header($status);
    header('PAYMENT-RESPONSE: ' . Facilitator::receipt_header($settled));
    header('Content-Type: ' . \X402\Sanitizer::safe_content_type($content_type));
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

/* ---------- routes ---------- */

add_action('rest_api_init', function (): void {
    register_rest_route('x402/v1', '/catalog', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (): WP_REST_Response {
            x402_no_store();
            $network  = (string) get_option('x402_network', 'eip155:84532');
            $products = [[
                'sku' => 'demo', 'title' => 'Demo: sample markdown', 'price' => '$0.01',
                'url' => rest_url('x402/v1/demo'), 'mimeType' => 'text/markdown', 'network' => $network,
            ]];
            foreach (x402_enabled_ask_products() as $p) {
                $products[] = [
                    'sku' => $p['sku'], 'title' => $p['title'], 'price' => $p['price'],
                    'url' => $p['url'], 'method' => 'POST', 'mimeType' => 'application/json', 'network' => $network,
                    'description' => $p['description'],
                ];
            }
            foreach (x402_all_item_products() as $p) {
                $products[] = [
                    'sku' => $p['sku'], 'title' => $p['title'], 'price' => $p['price'],
                    'url' => $p['url'], 'mimeType' => $p['mime_type'], 'network' => $network,
                    'description' => $p['description'],
                ];
            }
            foreach (x402_proxy_products() as $p) {
                $products[] = [
                    'sku' => $p['sku'], 'title' => $p['title'], 'price' => $p['price'],
                    'url' => rest_url('x402/v1/p/' . $p['sku']), 'network' => $network,
                ];
            }
            return new WP_REST_Response(['products' => $products], 200);
        },
    ]);

    register_rest_route('x402/v1', '/demo', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $request) {
            $product = x402_demo_product();
            // Never take money for content we can't deliver.
            if (!is_readable($product['file'])) {
                x402_no_store();
                error_log('[x402] product file missing: ' . $product['file']);
                return new WP_REST_Response(['error' => 'product unavailable'], 503);
            }
            return x402_paywall($product, $request, function (array $settled) use ($product): void {
                x402_emit_raw($settled, 200, $product['mime_type'] . '; charset=utf-8', (string) file_get_contents($product['file']));
            });
        },
    ]);

    // Bare /ask serves the sole enabled collection (convenience); many → point at /ask/{slug}.
    register_rest_route('x402/v1', '/ask', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $request) {
            x402_no_store();
            $enabled = x402_enabled_ask_products();
            if (count($enabled) === 1) {
                return x402_ask_collection($enabled[0]['collection'], $request);
            }
            if ($enabled === []) {
                return new WP_REST_Response(['error' => 'no ask collections enabled'], 404);
            }
            return new WP_REST_Response([
                'error'       => 'multiple ask collections — POST to /x402/v1/ask/{collection}',
                'collections' => array_map(fn ($p) => $p['collection'], $enabled),
            ], 400);
        },
    ]);

    register_rest_route('x402/v1', '/ask/(?P<slug>[a-z0-9-]+)', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => fn (WP_REST_Request $request) => x402_ask_collection((string) $request['slug'], $request),
    ]);

    register_rest_route('x402/v1', '/p/(?P<sku>[a-z0-9-]+)', [
        'methods'             => ['GET', 'POST'],
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $request) {
            x402_no_store();
            $sku   = (string) $request['sku'];
            $match = null;
            foreach (x402_proxy_products() as $p) {
                if ($p['sku'] === $sku) {
                    $match = $p;
                    break;
                }
            }
            if ($match === null) {
                return new WP_REST_Response(['error' => 'unknown product'], 404);
            }
            $product = [
                'sku'          => $match['sku'],
                'url'          => rest_url('x402/v1/p/' . $match['sku']),
                'description'  => $match['title'],
                'mime_type'    => '',
                'amount_micro' => Price::toMicro($match['price']),
                'network'      => x402_active_network(),
                'pay_to'       => x402_pay_to(),
            ];
            return x402_paywall($product, $request, function (array $settled) use ($match, $request) {
                $args = [
                    'method'  => $request->get_method(),
                    'timeout' => 30,
                ];
                if ($request->get_method() === 'POST') {
                    $args['body'] = $request->get_body();
                    if ($request->get_content_type()) {
                        $args['headers'] = ['Content-Type' => $request->get_content_type()['value']];
                    }
                }
                $upstream_url = $match['url'];
                $query_params = $request->get_query_params();
                unset($query_params['rest_route']); // WP routing artifact, never the buyer's query
                // Buyers may add params but never override the operator's own
                // (an upstream URL can carry embedded credentials).
                $operator_params = [];
                parse_str((string) (parse_url($upstream_url, PHP_URL_QUERY) ?: ''), $operator_params);
                $query_params = array_diff_key($query_params, $operator_params);
                if ($query_params) {
                    $upstream_url = add_query_arg(array_map('rawurlencode', $query_params), $upstream_url);
                }
                $upstream = wp_remote_request($upstream_url, $args);
                if (is_wp_error($upstream)) {
                    error_log('[x402] proxy upstream unreachable (' . $match['sku'] . '): ' . $upstream->get_error_message());
                    $response = new WP_REST_Response(['error' => 'upstream unavailable'], 502);
                    $response->header('PAYMENT-RESPONSE', Facilitator::receipt_header($settled));
                    return $response;
                }
                x402_emit_raw(
                    $settled,
                    (int) wp_remote_retrieve_response_code($upstream),
                    (string) (wp_remote_retrieve_header($upstream, 'content-type') ?: 'application/octet-stream'),
                    (string) wp_remote_retrieve_body($upstream)
                );
            });
        },
    ]);

    register_rest_route('x402/v1', '/i/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $request) {
            x402_no_store();
            if (!x402_rate_limit(x402_client_ip_hash(), 60, 60)) {
                return new WP_REST_Response(['error' => 'rate limit exceeded'], 429);
            }
            $product = x402_item_product((int) $request['id']);
            if ($product === null) {
                return new WP_REST_Response(['error' => 'not for sale'], 404);
            }
            // Browser visitor with no payment → themed hint, not a raw 402 JSON blob.
            $accept  = (string) $request->get_header('accept');
            $unpaid  = Facilitator::decode_payment($request->get_header('payment-signature') ?? $request->get_header('x-payment')) === null;
            if ($unpaid && str_contains($accept, 'text/html')) {
                x402_log_request($product['sku'], 'unpaid_402');
                return x402_browser_hint($product);
            }
            if ($product['file'] !== null && !is_readable($product['file'])) {
                error_log('[x402] attachment file missing: ' . (string) $product['file']);
                return new WP_REST_Response(['error' => 'product unavailable'], 503);
            }
            return x402_paywall($product, $request, function (array $settled) use ($product): void {
                if ($product['file'] !== null) {
                    x402_emit_raw($settled, 200, $product['mime_type'], (string) file_get_contents($product['file']));
                }
                $html = apply_filters('the_content', get_post_field('post_content', $product['post_id']));
                x402_emit_raw($settled, 200, 'text/html; charset=utf-8', (string) $html);
            });
        },
    ]);
});

/** Themed page telling a human what this URL is (agents get the raw 402 instead). */
function x402_browser_hint(array $product): WP_REST_Response
{
    $price = '$' . number_format($product['amount_micro'] / 1_000_000, 2);
    $url   = esc_url($product['url']);
    $body  = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . esc_html($product['title']) . ' — pay with x402</title>'
        . '<div style="max-width:38rem;margin:4rem auto;padding:0 1rem;font-family:system-ui,sans-serif;line-height:1.5">'
        . '<h1>' . esc_html($product['title']) . '</h1>'
        . '<p>' . esc_html($product['description']) . '</p>'
        . '<p>This resource is sold to AI agents over the <a href="https://x402.org">x402</a> payment protocol for <strong>' . esc_html($price) . '</strong> in USDC.</p>'
        . '<p>An x402-capable client pays automatically. To fetch it yourself:</p>'
        . '<pre style="background:#f4f4f5;padding:1rem;border-radius:.5rem;overflow:auto"><code>curl -sD - ' . $url . '</code></pre>'
        . '</div>';
    $response = new WP_REST_Response(null, 402);
    $response->header('payment-required', Challenge::header($product));
    $response->header('Content-Type', 'text/html; charset=utf-8');
    // WP_REST prints JSON; emit HTML directly instead.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    status_header(402);
    header('payment-required: ' . Challenge::header($product));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo $body;
    exit;
}

/* ---------- [x402_catalog] shortcode (theme-styled, zero custom CSS) ---------- */

add_shortcode('x402_catalog', function (): string {
    $items = [];
    foreach (x402_enabled_ask_products() as $p) {
        $items[] = ['title' => $p['title'], 'price' => $p['price'], 'url' => $p['url'], 'desc' => $p['description']];
    }
    foreach (x402_all_item_products() as $p) {
        $items[] = ['title' => $p['title'], 'price' => $p['price'], 'url' => $p['url'], 'desc' => $p['description']];
    }
    foreach (x402_proxy_products() as $p) {
        $items[] = ['title' => $p['title'], 'price' => $p['price'], 'url' => rest_url('x402/v1/p/' . $p['sku']), 'desc' => ''];
    }
    if (!$items) {
        return '<p>Nothing is for sale yet.</p>';
    }
    $out = '<ul class="x402-catalog">';
    foreach ($items as $it) {
        $out .= '<li><strong>' . esc_html($it['title']) . '</strong> — ' . esc_html($it['price'])
            . ($it['desc'] !== '' ? '<br><span>' . esc_html($it['desc']) . '</span>' : '')
            . '<br><code>' . esc_html($it['url']) . '</code></li>';
    }
    return $out . '</ul>';
});