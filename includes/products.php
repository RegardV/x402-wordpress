<?php
declare(strict_types=1);

use X402\Address;
use X402\CdpJwt;
use X402\Crypto;
use X402\Facilitator;
use X402\Price;

if (!defined('ABSPATH')) {
    exit;
}

const X402_MAINNET = 'eip155:8453';
const X402_TESTNET = 'eip155:84532';

/* ---------- network / wallet / facilitator (Tier 1 settings) ---------- */

function x402_active_network(): string
{
    return get_option('x402_network', X402_TESTNET) === X402_MAINNET ? X402_MAINNET : X402_TESTNET;
}

/** Receive address for the active network, with legacy single-address migration. */
function x402_pay_to(): string
{
    $key    = x402_active_network() === X402_MAINNET ? 'x402_pay_to_mainnet' : 'x402_pay_to_testnet';
    $addr   = (string) get_option($key, '');
    if ($addr === '' && x402_active_network() === X402_TESTNET) {
        $addr = (string) get_option('x402_pay_to', ''); // pre-Tier-1 single channel
    }
    return $addr;
}

/** A configured Facilitator for the active network: x402.org on testnet, CDP (JWT-authed) on mainnet. */
function x402_facilitator(): Facilitator
{
    $override = apply_filters('x402_facilitator_url', '');
    if ($override !== '') {
        return new Facilitator($override);
    }
    if (x402_active_network() === X402_MAINNET) {
        $key_id = (string) get_option('x402_cdp_key_id', '');
        $secret = Crypto::decrypt((string) get_option('x402_cdp_secret_enc', ''), wp_salt('secure_auth'));
        // verify/settle are both POST on the CDP facilitator.
        $auth   = ($key_id !== '' && $secret !== null)
            ? fn (string $endpoint): array => ['Authorization' => 'Bearer ' . CdpJwt::build($key_id, $secret, 'POST', '/platform/v2/x402/' . $endpoint)]
            : null;
        return new Facilitator('https://api.cdp.coinbase.com/platform/v2/x402', null, $auth);
    }
    return new Facilitator('https://x402.org/facilitator');
}

function x402_mainnet_ready(): bool
{
    return x402_pay_to() !== ''
        && get_option('x402_cdp_key_id', '') !== ''
        && Crypto::decrypt((string) get_option('x402_cdp_secret_enc', ''), wp_salt('secure_auth')) !== null;
}

/* ---------- meta-box products (t1.1) ---------- */

const X402_SELLABLE_TYPES = ['post', 'page', 'attachment'];

add_action('add_meta_boxes', function (): void {
    foreach (X402_SELLABLE_TYPES as $type) {
        add_meta_box('x402_sell', 'Sell to AI agents (x402)', 'x402_render_meta_box', $type, 'side');
    }
});

function x402_render_meta_box(WP_Post $post): void
{
    wp_nonce_field('x402_save_product', 'x402_product_nonce');
    $enabled = (bool) get_post_meta($post->ID, '_x402_enabled', true);
    $price   = (string) get_post_meta($post->ID, '_x402_price', true);
    $desc    = (string) get_post_meta($post->ID, '_x402_description', true);
    ?>
    <p><label><input type="checkbox" name="x402_enabled" value="1" <?php checked($enabled); ?> /> Sell this over x402</label></p>
    <p><label>Price<br/><input type="text" name="x402_price" value="<?php echo esc_attr($price); ?>" placeholder="$0.25" class="widefat" /></label></p>
    <p><label>Description (max 250)<br/><textarea name="x402_description" rows="3" maxlength="250" class="widefat" placeholder="What agents are buying"><?php echo esc_textarea($desc); ?></textarea></label></p>
    <p class="description">Sell URL: <code>/x402/v1/i/<?php echo (int) $post->ID; ?></code></p>
    <?php
}

add_action('save_post', function (int $post_id): void {
    if (!isset($_POST['x402_product_nonce']) || !wp_verify_nonce(sanitize_key($_POST['x402_product_nonce']), 'x402_save_product')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || !current_user_can('edit_post', $post_id)) {
        return;
    }
    $enabled = !empty($_POST['x402_enabled']);
    $price   = trim((string) ($_POST['x402_price'] ?? ''));
    if ($enabled) {
        try {
            Price::toMicro($price);
        } catch (InvalidArgumentException) {
            $enabled = false; // invalid price → not sellable, silently (meta box shows state on reload)
        }
    }
    update_post_meta($post_id, '_x402_enabled', $enabled ? '1' : '');
    update_post_meta($post_id, '_x402_price', $price);
    update_post_meta($post_id, '_x402_description', mb_substr(sanitize_text_field((string) ($_POST['x402_description'] ?? '')), 0, 250));
}, 20);

/** Product descriptor for a sellable post/page/attachment, or null. */
function x402_item_product(int $post_id): ?array
{
    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, X402_SELLABLE_TYPES, true) || !get_post_meta($post_id, '_x402_enabled', true)) {
        return null;
    }
    // Only publicly-published content is sellable — never drafts, private,
    // scheduled, trashed, or password-protected posts (the enabled flag can
    // outlive a status change, and IDs are enumerable on an open route).
    $required_status = $post->post_type === 'attachment' ? 'inherit' : 'publish';
    if ($post->post_status !== $required_status || $post->post_password !== '') {
        return null;
    }
    try {
        $amount = Price::toMicro((string) get_post_meta($post_id, '_x402_price', true));
    } catch (InvalidArgumentException) {
        return null;
    }
    $is_attachment = $post->post_type === 'attachment';
    return [
        'sku'          => 'i-' . $post_id,
        'post_id'      => $post_id,
        'title'        => $post->post_title,
        'url'          => rest_url('x402/v1/i/' . $post_id),
        'description'  => (string) (get_post_meta($post_id, '_x402_description', true) ?: $post->post_title),
        'mime_type'    => $is_attachment ? (string) get_post_mime_type($post_id) : 'text/html',
        'amount_micro' => $amount,
        'price'        => (string) get_post_meta($post_id, '_x402_price', true),
        'network'      => x402_active_network(),
        'pay_to'       => x402_pay_to(),
        'file'         => $is_attachment ? get_attached_file($post_id) : null,
    ];
}

/** All enabled meta-box products (for the catalog). */
function x402_all_item_products(): array
{
    $ids = get_posts([
        'post_type'   => X402_SELLABLE_TYPES,
        'post_status' => ['publish', 'inherit'],
        'numberposts' => 100,
        'meta_key'    => '_x402_enabled',
        'meta_value'  => '1',
        'fields'      => 'ids',
    ]);
    return array_values(array_filter(array_map('x402_item_product', $ids)));
}