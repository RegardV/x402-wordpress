<?php
declare(strict_types=1);

use X402\Crypto;
use X402\Setup;

if (!defined('ABSPATH')) {
    exit;
}

/** True when the wizard should own the page: never configured, or explicitly relaunched. */
function x402_wizard_active(): bool
{
    return !get_option('x402_setup_complete') || isset($_GET['wizard']);
}

/** Save handler — validates the entered mode+wallet(+keys), persists, activates the network. */
add_action('admin_post_x402_wizard_save', function (): void {
    if (!current_user_can('manage_options') || !check_admin_referer('x402_wizard_save')) {
        wp_die('forbidden');
    }
    $is_mainnet    = ($_POST['mode'] ?? '') === X402_MAINNET;
    $address       = trim((string) ($_POST['address'] ?? ''));
    $key_id        = trim((string) ($_POST['cdp_key_id'] ?? ''));
    $secret        = trim((string) ($_POST['cdp_secret'] ?? ''));
    $secret_stored = get_option('x402_cdp_secret_enc', '') !== '';

    $error = Setup::validate($is_mainnet, $address, $key_id, $secret, $secret_stored);
    if ($error !== null) {
        set_transient('x402_wizard_error', $error, 60);
        // Keep what they just typed (never the secret) so a typo doesn't wipe the form.
        set_transient('x402_wizard_input', ['address' => $address, 'key_id' => $key_id], 60);
        wp_safe_redirect(admin_url('admin.php?page=x402&wizard=1&step=2&mode=' . rawurlencode($is_mainnet ? X402_MAINNET : X402_TESTNET)));
        exit;
    }

    update_option($is_mainnet ? 'x402_pay_to_mainnet' : 'x402_pay_to_testnet', $address);
    if ($is_mainnet) {
        update_option('x402_cdp_key_id', $key_id);
        if ($secret !== '') {
            update_option('x402_cdp_secret_enc', Crypto::encrypt($secret, wp_salt('secure_auth')), false);
        }
    }
    update_option('x402_network', $is_mainnet ? X402_MAINNET : X402_TESTNET);
    update_option('x402_setup_complete', 1);

    wp_safe_redirect(admin_url('admin.php?page=x402&wizard=1&step=3'));
    exit;
});

/** Renders whichever wizard step the query string asks for. */
function x402_render_wizard(): void
{
    $step = max(1, min(3, (int) ($_GET['step'] ?? 1)));
    echo '<div class="wrap" style="max-width:640px">';
    echo '<h1>x402 setup</h1>';

    if ($error = get_transient('x402_wizard_error')) {
        delete_transient('x402_wizard_error');
        echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
    }

    if ($step === 1) {
        x402_wizard_step_choose();
    } elseif ($step === 2) {
        x402_wizard_step_details();
    } else {
        x402_wizard_step_done();
    }
    echo '</div>';
}

/** Step 1 — testnet or mainnet. */
function x402_wizard_step_choose(): void
{
    $current = x402_active_network();
    ?>
    <p class="description" style="font-size:14px">Step 1 of 3 — choose how you'll get paid.</p>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="x402" />
        <input type="hidden" name="wizard" value="1" />
        <input type="hidden" name="step" value="2" />
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Network</th>
                <td>
                    <p><label><input type="radio" name="mode" value="<?php echo esc_attr(X402_TESTNET); ?>" <?php checked($current !== X402_MAINNET); ?> />
                        <strong>Testnet</strong> (Base Sepolia) — play money, no signup. Recommended to start.</label></p>
                    <p><label><input type="radio" name="mode" value="<?php echo esc_attr(X402_MAINNET); ?>" <?php checked($current === X402_MAINNET); ?> />
                        <strong>Mainnet</strong> (Base) — real USDC. Needs free Coinbase CDP API keys.</label></p>
                </td>
            </tr>
        </table>
        <?php submit_button('Continue →'); ?>
    </form>
    <?php
}

/** Step 2 — wallet (and CDP keys on mainnet), pre-filled from anything already saved. */
function x402_wizard_step_details(): void
{
    $mode          = ($_GET['mode'] ?? '') === X402_MAINNET ? X402_MAINNET : X402_TESTNET;
    $is_mainnet    = $mode === X402_MAINNET;
    $address       = (string) get_option($is_mainnet ? 'x402_pay_to_mainnet' : 'x402_pay_to_testnet', '');
    $key_id        = (string) get_option('x402_cdp_key_id', '');
    $secret_stored = get_option('x402_cdp_secret_enc', '') !== '';
    // Prefer what was just typed on a validation bounce-back.
    if ($retry = get_transient('x402_wizard_input')) {
        delete_transient('x402_wizard_input');
        $address = (string) ($retry['address'] ?? $address);
        $key_id  = (string) ($retry['key_id'] ?? $key_id);
    }
    ?>
    <p class="description" style="font-size:14px">Step 2 of 3 — <?php echo $is_mainnet ? 'mainnet (real USDC)' : 'testnet'; ?> details. Funds settle straight to your wallet; no keys leave your control.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('x402_wizard_save'); ?>
        <input type="hidden" name="action" value="x402_wizard_save" />
        <input type="hidden" name="mode" value="<?php echo esc_attr($mode); ?>" />
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="address">Receive wallet address</label></th>
                <td><input type="text" id="address" name="address" class="regular-text code" value="<?php echo esc_attr($address); ?>" placeholder="0x…" required /></td>
            </tr>
            <?php if ($is_mainnet) : ?>
            <tr>
                <th scope="row"><label for="cdp_key_id">CDP API key ID</label></th>
                <td><input type="text" id="cdp_key_id" name="cdp_key_id" class="regular-text code" value="<?php echo esc_attr($key_id); ?>" placeholder="organizations/…/apiKeys/…" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="cdp_secret">CDP API key secret</label></th>
                <td>
                    <input type="password" id="cdp_secret" name="cdp_secret" class="regular-text code" value="" autocomplete="off" placeholder="<?php echo $secret_stored ? '•••••• stored — leave blank to keep' : 'base64 Ed25519 secret'; ?>" />
                    <p class="description">Stored encrypted. Free from the <a href="https://portal.cdp.coinbase.com" target="_blank" rel="noopener">Coinbase Developer Platform</a> — this authenticates settlement; funds still go to your wallet, never Coinbase's.</p>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=x402&wizard=1&step=1')); ?>" class="button">← Back</a>
            <?php submit_button($is_mainnet ? 'Save &amp; go live' : 'Save', 'primary', 'submit', false); ?>
        </p>
    </form>
    <?php
}

/** Step 3 — confirmation: what's set and where the store lives. */
function x402_wizard_step_done(): void
{
    $is_mainnet = x402_active_network() === X402_MAINNET;
    $ready      = $is_mainnet ? x402_mainnet_ready() : x402_pay_to() !== '';
    ?>
    <div class="notice notice-success" style="padding:12px 16px">
        <h2 style="margin-top:0">
            <span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>
            <?php echo $is_mainnet ? 'Mainnet is live — real USDC' : 'Testnet is set'; ?>
        </h2>
        <p>Receive address: <code><?php echo esc_html(x402_pay_to()); ?></code><?php echo $is_mainnet ? ' · CDP keys stored (encrypted)' : ''; ?></p>
        <?php if (!$ready) : ?>
            <p><span class="dashicons dashicons-warning" style="color:#dba617"></span> Mainnet needs both a wallet and CDP keys — <a href="<?php echo esc_url(admin_url('admin.php?page=x402&wizard=1&step=2&mode=' . X402_MAINNET)); ?>">finish setup</a>.</p>
        <?php endif; ?>
    </div>
    <p>Agents hitting your store now get an HTTP 402 challenge and can pay in USDC:</p>
    <table class="widefat striped" style="max-width:640px">
        <tbody>
            <tr><td>Catalog</td><td><code><?php echo esc_html(rest_url('x402/v1/catalog')); ?></code></td></tr>
            <tr><td>Ask endpoint</td><td><code><?php echo esc_html(rest_url('x402/v1/ask')); ?></code></td></tr>
        </tbody>
    </table>
    <p style="margin-top:20px">
        <a href="<?php echo esc_url(admin_url('admin.php?page=x402')); ?>" class="button button-primary">Go to dashboard →</a>
    </p>
    <?php
}

/** The banner + switch control shown on the configured dashboard. */
function x402_network_switch_banner(): void
{
    $is_mainnet = x402_active_network() === X402_MAINNET;
    $other      = $is_mainnet ? X402_TESTNET : X402_MAINNET;
    $other_name = $is_mainnet ? 'testnet' : 'mainnet';
    $switch_url = admin_url('admin.php?page=x402&wizard=1&step=2&mode=' . rawurlencode($other));
    ?>
    <div class="notice notice-info inline" style="display:flex;align-items:center;justify-content:space-between;max-width:900px;margin:12px 0;padding:8px 14px">
        <span>Active network: <strong><?php echo $is_mainnet ? 'Mainnet (real USDC)' : 'Testnet (Base Sepolia)'; ?></strong></span>
        <a href="<?php echo esc_url($switch_url); ?>" class="button">Switch to <?php echo esc_html($other_name); ?> →</a>
    </div>
    <?php
}