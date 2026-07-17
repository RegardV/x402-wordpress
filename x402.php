<?php
/**
 * Plugin Name: x402 for WordPress
 * Description: Sell files, endpoints, and answers to AI agents for USDC over the x402 protocol. Self-custodial, 0% fees.
 * Version: 0.1.0
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
require_once __DIR__ . '/includes/indexer.php';
require_once __DIR__ . '/includes/admin-page.php';

use X402\Challenge;
use X402\Facilitator;
use X402\Price;
use X402\Search;
use X402\Settlements;

const X402_TESTNET_FACILITATOR = 'https://x402.org/facilitator';
const X402_RESERVED_SKUS       = ['demo', 'ask', 'catalog'];

register_activation_hook(__FILE__, function (): void {
    delete_option('x402_db_version'); // force schema pass on next load
    x402_upgrade_db();
});

/* ---------- products ---------- */

function x402_demo_product(): array
{
    return [
        'sku'          => 'demo',
        'url'          => rest_url('x402/v1/demo'),
        'description'  => 'x402 for WordPress demo product — a sample markdown file sold over the x402 protocol.',
        'mime_type'    => 'text/markdown',
        'amount_micro' => Price::toMicro('$0.01'),
        'network'      => get_option('x402_network', 'eip155:84532'),
        'pay_to'       => (string) get_option('x402_pay_to', ''),
        'file'         => __DIR__ . '/sample-content.md',
    ];
}

function x402_ask_product(): array
{
    return [
        'sku'          => 'ask',
        'url'          => rest_url('x402/v1/ask'),
        'description'  => (string) get_option('x402_ask_description', 'Paid retrieval over this site\'s indexed content. POST {"query": "..."} and get cited passages.'),
        'mime_type'    => 'application/json',
        'amount_micro' => Price::toMicro((string) get_option('x402_ask_price', '$0.02')),
        'network'      => get_option('x402_network', 'eip155:84532'),
        'pay_to'       => (string) get_option('x402_pay_to', ''),
    ];
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

/**
 * The payment wall: 402 challenge → verify → settle → ledger → $deliver($settled).
 * $deliver returns the WP_REST_Response for the buyer (or emits raw bytes and exits).
 */
function x402_paywall(array $product, WP_REST_Request $request, callable $deliver)
{
    x402_no_store();
    if ($product['pay_to'] === '') {
        return new WP_REST_Response(['error' => 'x402 receive wallet not configured'], 503);
    }

    $payment = Facilitator::decode_payment(
        $request->get_header('payment-signature') ?? $request->get_header('x-payment')
    );
    if ($payment === null) {
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
    $facilitator  = new Facilitator(X402_TESTNET_FACILITATOR);

    $verified = $facilitator->verify($payment, $requirements);
    if (!$verified['ok']) {
        error_log('[x402] verify failed (' . $product['sku'] . '): ' . $verified['error']);
        return x402_402_response($product);
    }

    $settled = $facilitator->settle($payment, $requirements);
    if (!$settled['ok']) {
        error_log('[x402] settle failed (' . $product['sku'] . '): ' . $settled['error']);
        return x402_402_response($product);
    }

    // Duplicate tx = redelivery of an already-paid purchase, never a recharge.
    if ($settled['tx'] !== '') {
        global $wpdb;
        (new Settlements($wpdb))->record_once($product['sku'], $product['amount_micro'], $settled);
    } else {
        error_log('[x402] settled without transaction hash — ledger row skipped: ' . (string) json_encode($settled['raw']));
    }

    return $deliver($settled);
}

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
            if (get_option('x402_ask_enabled')) {
                $products[] = [
                    'sku' => 'ask', 'title' => 'Ask this site (per query)', 'price' => (string) get_option('x402_ask_price', '$0.02'),
                    'url' => rest_url('x402/v1/ask'), 'method' => 'POST', 'mimeType' => 'application/json', 'network' => $network,
                    'description' => x402_ask_product()['description'],
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

    register_rest_route('x402/v1', '/ask', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $request) {
            x402_no_store();
            if (!get_option('x402_ask_enabled')) {
                return new WP_REST_Response(['error' => 'ask endpoint is not enabled'], 404);
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
            $chunk_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}x402_chunks");
            if ($chunk_count === 0) {
                return new WP_REST_Response(['error' => 'nothing indexed yet'], 503);
            }

            return x402_paywall(x402_ask_product(), $request, function (array $settled) use ($wpdb, $query, $boolean, $top_k) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT source, source_id, source_name, heading, content,
                            MATCH(heading, content) AGAINST(%s IN BOOLEAN MODE) AS score
                     FROM {$wpdb->prefix}x402_chunks
                     WHERE MATCH(heading, content) AGAINST(%s IN BOOLEAN MODE)
                     ORDER BY score DESC LIMIT %d",
                    $boolean,
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

                $response = new WP_REST_Response(['query' => $query, 'count' => count($results), 'results' => $results], 200);
                $response->header('PAYMENT-RESPONSE', Facilitator::receipt_header($settled));
                $response->header('X-Content-Type-Options', 'nosniff');
                return $response;
            });
        },
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
                'network'      => get_option('x402_network', 'eip155:84532'),
                'pay_to'       => (string) get_option('x402_pay_to', ''),
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
});