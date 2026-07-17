<?php
declare(strict_types=1);

use X402\Address;

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function (): void {
    add_menu_page('x402', 'x402', 'manage_options', 'x402', 'x402_render_admin_page', 'dashicons-money-alt');
});

add_action('admin_init', function (): void {
    register_setting('x402', 'x402_pay_to', [
        'type'              => 'string',
        'sanitize_callback' => function ($value): string {
            $value = trim((string) $value);
            if ($value !== '' && !Address::is_valid($value)) {
                add_settings_error('x402_pay_to', 'x402_pay_to', 'Not a valid address — expected 0x followed by 40 hex characters.');
                return (string) get_option('x402_pay_to', '');
            }
            return $value;
        },
    ]);
});

/** Point the operator at the page once, right after activation. */
add_action('admin_notices', function (): void {
    if (get_option('x402_pay_to', '') === '' && current_user_can('manage_options')) {
        $url = esc_url(admin_url('admin.php?page=x402'));
        echo '<div class="notice notice-warning"><p><strong>x402:</strong> set your receive wallet address to start selling — <a href="' . $url . '">x402 settings</a>.</p></div>';
    }
});

function x402_render_admin_page(): void
{
    global $wpdb;
    $pay_to  = (string) get_option('x402_pay_to', '');
    $table   = $wpdb->prefix . 'x402_settlements';
    $sales   = $wpdb->get_results("SELECT tx_hash, payer, product_ref, amount_usdc_micro, network, created_at FROM $table ORDER BY id DESC LIMIT 10", ARRAY_A) ?: [];
    $totals  = $wpdb->get_row("SELECT COUNT(*) AS n, COALESCE(SUM(amount_usdc_micro),0) AS micro FROM $table", ARRAY_A) ?: ['n' => 0, 'micro' => 0];
    $catalog = rest_url('x402/v1/catalog');
    $demo    = rest_url('x402/v1/demo');
    ?>
    <div class="wrap">
        <h1>x402 — sell to AI agents for USDC</h1>

        <?php settings_errors(); ?>

        <h2 class="title">Wallet</h2>
        <p>Payments settle on-chain <strong>directly to this address</strong>. It is a receive address only — no private keys are ever stored in WordPress.</p>
        <form method="post" action="options.php">
            <?php settings_fields('x402'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="x402_pay_to">Receive address (Base Sepolia testnet)</label></th>
                    <td>
                        <input type="text" id="x402_pay_to" name="x402_pay_to" class="regular-text code"
                               value="<?php echo esc_attr($pay_to); ?>" placeholder="0x…" />
                        <p class="description">Tier 0 runs on the <strong>Base Sepolia testnet</strong> (facilitator: x402.org, no API keys needed). Mainnet arrives with the settings page in Tier 1.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save'); ?>
        </form>

        <h2 class="title">Your store endpoints</h2>
        <?php if ($pay_to === '') : ?>
            <p><span class="dashicons dashicons-warning" style="color:#dba617"></span> Not selling yet — save a receive address above first.</p>
        <?php else : ?>
            <p><span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> Live. Agents hitting these URLs get an HTTP 402 challenge and can pay in USDC:</p>
        <?php endif; ?>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th>What</th><th>URL</th></tr></thead>
            <tbody>
                <tr><td>Catalog (free, machine-readable)</td><td><code><?php echo esc_html($catalog); ?></code></td></tr>
                <tr><td>Demo product — sample markdown, $0.01</td><td><code><?php echo esc_html($demo); ?></code></td></tr>
            </tbody>
        </table>
        <p class="description">Try it: <code>curl -sD - "<?php echo esc_html($demo); ?>"</code> → the 402 challenge. Any x402-capable client can complete the purchase.</p>

        <h2 class="title">Sales</h2>
        <p><strong><?php echo (int) $totals['n']; ?></strong> settled · <strong>$<?php echo esc_html(number_format(((int) $totals['micro']) / 1_000_000, 2)); ?></strong> USDC total</p>
        <?php if ($sales) : ?>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>When (UTC)</th><th>Product</th><th>Amount</th><th>Payer</th><th>Transaction</th><th>Network</th></tr></thead>
                <tbody>
                <?php foreach ($sales as $s) : ?>
                    <tr>
                        <td><?php echo esc_html($s['created_at']); ?></td>
                        <td><?php echo esc_html($s['product_ref']); ?></td>
                        <td>$<?php echo esc_html(number_format(((int) $s['amount_usdc_micro']) / 1_000_000, 2)); ?></td>
                        <td><code><?php echo esc_html(substr($s['payer'], 0, 10)); ?>…</code></td>
                        <td><code><?php echo esc_html(substr($s['tx_hash'], 0, 14)); ?>…</code></td>
                        <td><?php echo esc_html($s['network']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description">No sales yet. The first settlement will appear here.</p>
        <?php endif; ?>
    </div>
    <?php
}