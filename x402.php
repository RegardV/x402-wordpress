<?php
/**
 * Plugin Name: x402 for WordPress
 * Description: Sell files, endpoints, and answers to AI agents for USDC over the x402 protocol. Self-custodial, 0% fees.
 * Version: 0.0.1
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

use X402\Challenge;
use X402\Facilitator;
use X402\Price;
use X402\Settlements;

const X402_TESTNET_FACILITATOR = 'https://x402.org/facilitator';

register_activation_hook(__FILE__, function (): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta(Settlements::table_sql($wpdb->prefix, $wpdb->get_charset_collate()));
});

/** Tier 0: one hardcoded product. Options: x402_pay_to (receive address), x402_network. */
function x402_demo_product(): array
{
    return [
        'url'          => rest_url('x402/v1/demo'),
        'description'  => 'x402 for WordPress demo product — a sample markdown file sold over the x402 protocol.',
        'mime_type'    => 'text/markdown',
        'amount_micro' => Price::toMicro('$0.01'),
        'network'      => get_option('x402_network', 'eip155:84532'),
        'pay_to'       => (string) get_option('x402_pay_to', ''),
        'file'         => __DIR__ . '/sample-content.md',
    ];
}

/** Every agent-lane response: never cacheable. A cached 402 kills the store. */
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

add_action('rest_api_init', function (): void {
    register_rest_route('x402/v1', '/catalog', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (): WP_REST_Response {
            x402_no_store();
            $product = x402_demo_product();
            return new WP_REST_Response(['products' => [[
                'sku'      => 'demo',
                'title'    => 'Demo: sample markdown',
                'price'    => '$0.01',
                'url'      => $product['url'],
                'mimeType' => $product['mime_type'],
                'network'  => $product['network'],
            ]]], 200);
        },
    ]);

    register_rest_route('x402/v1', '/demo', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $request) {
            x402_no_store();
            $product = x402_demo_product();
            if ($product['pay_to'] === '') {
                return new WP_REST_Response(['error' => 'x402_pay_to option not configured'], 503);
            }
            // Never take money for content we can't deliver.
            if (!is_readable($product['file'])) {
                error_log('[x402] product file missing: ' . $product['file']);
                return new WP_REST_Response(['error' => 'product unavailable'], 503);
            }

            // SDK ≥2.18 sends payment-signature; older clients send x-payment.
            $payment = Facilitator::decode_payment(
                $request->get_header('payment-signature') ?? $request->get_header('x-payment')
            );
            if ($payment === null) {
                return x402_402_response($product);
            }

            global $wpdb;
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
                error_log('[x402] verify failed: ' . $verified['error']);
                return x402_402_response($product);
            }

            $settled = $facilitator->settle($payment, $requirements);
            if (!$settled['ok']) {
                error_log('[x402] settle failed: ' . $settled['error']);
                return x402_402_response($product);
            }

            // Duplicate tx = redelivery of an already-paid purchase, never a recharge.
            if ($settled['tx'] !== '') {
                (new Settlements($wpdb))->record_once('demo', $product['amount_micro'], $settled);
            } else {
                error_log('[x402] settled without transaction hash — ledger row skipped: ' . (string) json_encode($settled['raw']));
            }

            // Raw delivery bypasses the REST JSON pipeline; drop anything already
            // buffered (deprecation notices etc.) so it can't corrupt the paid bytes.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('PAYMENT-RESPONSE: ' . Facilitator::receipt_header($settled));
            header('Content-Type: ' . $product['mime_type'] . '; charset=utf-8');
            readfile($product['file']);
            exit;
        },
    ]);
});