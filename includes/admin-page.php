<?php
declare(strict_types=1);

use X402\Address;
use X402\Price;
use X402\Sanitizer;

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
    register_setting('x402', 'x402_ask_enabled', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean']);
    register_setting('x402', 'x402_ask_index_posts', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean']);
    register_setting('x402', 'x402_ask_price', [
        'type'              => 'string',
        'sanitize_callback' => function ($value): string {
            $value = trim((string) $value);
            try {
                Price::toMicro($value);
                return $value;
            } catch (InvalidArgumentException) {
                add_settings_error('x402_ask_price', 'x402_ask_price', 'Invalid price — use e.g. $0.02');
                return (string) get_option('x402_ask_price', '$0.02');
            }
        },
    ]);
    register_setting('x402', 'x402_ask_description', [
        'type'              => 'string',
        'sanitize_callback' => fn ($v): string => mb_substr(sanitize_text_field((string) $v), 0, 250),
    ]);
});

add_action('admin_notices', function (): void {
    if (get_option('x402_pay_to', '') === '' && current_user_can('manage_options')) {
        $url = esc_url(admin_url('admin.php?page=x402'));
        echo '<div class="notice notice-warning"><p><strong>x402:</strong> set your receive wallet address to start selling — <a href="' . $url . '">x402 settings</a>.</p></div>';
    }
    if ($report = get_transient('x402_import_report')) {
        delete_transient('x402_import_report');
        echo '<div class="notice notice-info is-dismissible"><p><strong>x402 corpus import:</strong> ' . esc_html($report['summary']) . '</p>';
        if (!empty($report['rejected'])) {
            echo '<ul style="list-style:disc;margin-left:2em">';
            foreach (array_slice($report['rejected'], 0, 20) as $line) {
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
});

/* ---------- corpus import ---------- */

add_action('admin_post_x402_import_corpus', function (): void {
    if (!current_user_can('manage_options') || !check_admin_referer('x402_import_corpus')) {
        wp_die('forbidden');
    }
    $file = $_FILES['corpus_zip'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        set_transient('x402_import_report', ['summary' => 'upload failed — check the file and your host\'s upload_max_filesize (' . ini_get('upload_max_filesize') . ')', 'rejected' => []], 300);
        wp_safe_redirect(admin_url('admin.php?page=x402'));
        exit;
    }

    set_transient('x402_import_report', x402_import_zip($file['tmp_name']), 300);
    wp_safe_redirect(admin_url('admin.php?page=x402'));
    exit;
});

/** @return array{summary:string, rejected:string[]} */
function x402_import_zip(string $zip_path): array
{
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return ['summary' => 'not a readable zip archive', 'rejected' => []];
    }
    if ($zip->numFiles > 2000) {
        $zip->close();
        return ['summary' => 'archive has more than 2000 entries — split the corpus', 'rejected' => []];
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(300); // thousands of small inserts must survive shared-host limits
    }
    $accepted = 0;
    $chunks   = 0;
    $rejected = [];
    $budget   = 64 * 1024 * 1024; // total uncompressed bytes
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_ends_with($name, '/')) {
            continue; // directory entry
        }
        if ($why = Sanitizer::check_name($name)) {
            $rejected[] = "$name — $why";
            continue;
        }
        $stat = $zip->statIndex($i);
        if (($stat['size'] ?? 0) > 2 * 1024 * 1024) {
            $rejected[] = "$name — larger than 2MB";
            continue;
        }
        $budget -= (int) ($stat['size'] ?? 0);
        if ($budget < 0) {
            $rejected[] = "$name — 64MB total budget exhausted, remaining entries skipped";
            break;
        }
        $raw = $zip->getFromIndex($i);
        if ($raw === false || ($clean = Sanitizer::clean($raw)) === null) {
            $rejected[] = "$name — binary or key material";
            continue;
        }
        $existing = get_posts(['post_type' => 'x402_corpus', 'post_status' => 'private', 'title' => $name, 'numberposts' => 1, 'fields' => 'ids']);
        $post_id  = $existing
            ? (wp_update_post(['ID' => $existing[0], 'post_content' => $clean]) ?: 0)
            : (int) wp_insert_post(['post_type' => 'x402_corpus', 'post_status' => 'private', 'post_title' => $name, 'post_content' => $clean]);
        if ($post_id <= 0) {
            $rejected[] = "$name — could not store";
            continue;
        }
        $chunks += x402_index_content('corpus', $post_id, $name, $clean);
        $accepted++;
    }
    $zip->close();
    return [
        'summary'  => "$accepted files imported ($chunks chunks indexed), " . count($rejected) . ' rejected.',
        'rejected' => $rejected,
    ];
}

add_action('admin_post_x402_reindex', function (): void {
    if (!current_user_can('manage_options') || !check_admin_referer('x402_reindex')) {
        wp_die('forbidden');
    }
    $r = x402_reindex_all();
    set_transient('x402_import_report', ['summary' => "reindexed {$r['docs']} documents into {$r['chunks']} chunks.", 'rejected' => []], 300);
    wp_safe_redirect(admin_url('admin.php?page=x402'));
    exit;
});

/* ---------- proxy products ---------- */

add_action('admin_post_x402_proxy_add', function (): void {
    if (!current_user_can('manage_options') || !check_admin_referer('x402_proxy_add')) {
        wp_die('forbidden');
    }
    $sku   = sanitize_key((string) ($_POST['sku'] ?? ''));
    $title = sanitize_text_field((string) ($_POST['title'] ?? ''));
    $price = trim((string) ($_POST['price'] ?? ''));
    $url   = esc_url_raw(trim((string) ($_POST['url'] ?? '')), ['http', 'https']);
    $error = '';
    if ($sku === '' || in_array($sku, X402_RESERVED_SKUS, true)) {
        $error = 'invalid or reserved sku';
    } elseif (array_filter(x402_proxy_products(), fn ($p) => $p['sku'] === $sku)) {
        $error = "sku '$sku' already exists";
    } elseif ($title === '') {
        $error = 'title is required';
    } elseif ($url === '') {
        $error = 'upstream URL must be http(s)';
    } else {
        try {
            Price::toMicro($price);
        } catch (InvalidArgumentException) {
            $error = 'invalid price — use e.g. $0.05';
        }
    }
    if ($error !== '') {
        set_transient('x402_import_report', ['summary' => "proxy product not added: $error", 'rejected' => []], 300);
    } else {
        $list   = x402_proxy_products();
        $list[] = ['sku' => $sku, 'title' => $title, 'price' => $price, 'url' => $url];
        update_option('x402_proxy_products', $list);
        set_transient('x402_import_report', ['summary' => "proxy product '$sku' added.", 'rejected' => []], 300);
    }
    wp_safe_redirect(admin_url('admin.php?page=x402'));
    exit;
});

add_action('admin_post_x402_proxy_delete', function (): void {
    if (!current_user_can('manage_options') || !check_admin_referer('x402_proxy_delete')) {
        wp_die('forbidden');
    }
    $sku  = sanitize_key((string) ($_POST['sku'] ?? ''));
    $list = array_values(array_filter(x402_proxy_products(), fn ($p) => $p['sku'] !== $sku));
    update_option('x402_proxy_products', $list);
    set_transient('x402_import_report', ['summary' => "proxy product '$sku' removed.", 'rejected' => []], 300);
    wp_safe_redirect(admin_url('admin.php?page=x402'));
    exit;
});

/* ---------- the page ---------- */

function x402_render_admin_page(): void
{
    global $wpdb;
    $pay_to      = (string) get_option('x402_pay_to', '');
    $table       = $wpdb->prefix . 'x402_settlements';
    $sales       = $wpdb->get_results("SELECT tx_hash, payer, product_ref, amount_usdc_micro, network, created_at FROM $table ORDER BY id DESC LIMIT 10", ARRAY_A) ?: [];
    $totals      = $wpdb->get_row("SELECT COUNT(*) AS n, COALESCE(SUM(amount_usdc_micro),0) AS micro FROM $table", ARRAY_A) ?: ['n' => 0, 'micro' => 0];
    $chunk_stats = $wpdb->get_row("SELECT COUNT(*) AS n, COUNT(DISTINCT CONCAT(source, source_id)) AS docs FROM {$wpdb->prefix}x402_chunks", ARRAY_A) ?: ['n' => 0, 'docs' => 0];
    ?>
    <div class="wrap">
        <h1>x402 — sell to AI agents for USDC</h1>
        <?php settings_errors(); ?>

        <h2 class="title">Wallet &amp; ask endpoint</h2>
        <p>Payments settle on-chain <strong>directly to this address</strong>. Receive address only — no private keys are ever stored in WordPress.</p>
        <form method="post" action="options.php">
            <?php settings_fields('x402'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="x402_pay_to">Receive address (Base Sepolia testnet)</label></th>
                    <td>
                        <input type="text" id="x402_pay_to" name="x402_pay_to" class="regular-text code" value="<?php echo esc_attr($pay_to); ?>" placeholder="0x…" />
                        <p class="description">Tier 2 runs on the Base Sepolia testnet (facilitator: x402.org, no API keys). Mainnet lands with Tier 1's settings channels.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Ask endpoint</th>
                    <td>
                        <label><input type="checkbox" name="x402_ask_enabled" value="1" <?php checked(get_option('x402_ask_enabled')); ?> /> Sell answers: paid <code>POST /x402/v1/ask</code> over your indexed content</label><br/>
                        <label><input type="checkbox" name="x402_ask_index_posts" value="1" <?php checked(get_option('x402_ask_index_posts')); ?> /> Index published posts &amp; pages (run Reindex after changing)</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="x402_ask_price">Price per query</label></th>
                    <td><input type="text" id="x402_ask_price" name="x402_ask_price" class="small-text" value="<?php echo esc_attr((string) get_option('x402_ask_price', '$0.02')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="x402_ask_description">Ask description (max 250 chars — facilitator limit)</label></th>
                    <td><input type="text" id="x402_ask_description" name="x402_ask_description" class="large-text" maxlength="250" value="<?php echo esc_attr((string) get_option('x402_ask_description', '')); ?>" placeholder="What can agents ask this site about?" /></td>
                </tr>
            </table>
            <?php submit_button('Save'); ?>
        </form>

        <h2 class="title">Knowledge index</h2>
        <p><strong><?php echo (int) $chunk_stats['docs']; ?></strong> documents · <strong><?php echo (int) $chunk_stats['n']; ?></strong> searchable chunks</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin-bottom:8px">
            <?php wp_nonce_field('x402_import_corpus'); ?>
            <input type="hidden" name="action" value="x402_import_corpus" />
            <input type="file" name="corpus_zip" accept=".zip" required />
            <?php submit_button('Import corpus (zip of markdown)', 'secondary', 'submit', false); ?>
            <p class="description">A vault of .md/.txt files. Files are sanitized on the way in (dotfiles, binaries, key material rejected; frontmatter stripped), stored <strong>privately</strong> — never rendered on your site — and sold only as answers through the ask endpoint. Host upload limit: <?php echo esc_html((string) ini_get('upload_max_filesize')); ?>.</p>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('x402_reindex'); ?>
            <input type="hidden" name="action" value="x402_reindex" />
            <?php submit_button('Reindex everything', 'secondary', 'submit', false); ?>
        </form>

        <h2 class="title">Proxy products</h2>
        <p>Sell any endpoint: buyers pay here, the request is forwarded to your upstream URL and the response is delivered. Upstream URLs are operator-entered only. <strong>Payment settles before the forward</strong> — if your upstream is down, the buyer is charged and gets a 502, so keep upstreams reliable.</p>
        <?php if (x402_proxy_products()) : ?>
            <table class="widefat striped" style="max-width:900px;margin-bottom:8px">
                <thead><tr><th>SKU</th><th>Title</th><th>Price</th><th>Upstream</th><th>Sell URL</th><th></th></tr></thead>
                <tbody>
                <?php foreach (x402_proxy_products() as $p) : ?>
                    <tr>
                        <td><code><?php echo esc_html($p['sku']); ?></code></td>
                        <td><?php echo esc_html($p['title']); ?></td>
                        <td><?php echo esc_html($p['price']); ?></td>
                        <td><code><?php echo esc_html($p['url']); ?></code></td>
                        <td><code><?php echo esc_html(rest_url('x402/v1/p/' . $p['sku'])); ?></code></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('x402_proxy_delete'); ?>
                                <input type="hidden" name="action" value="x402_proxy_delete" />
                                <input type="hidden" name="sku" value="<?php echo esc_attr($p['sku']); ?>" />
                                <?php submit_button('Remove', 'link-delete', 'submit', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('x402_proxy_add'); ?>
            <input type="hidden" name="action" value="x402_proxy_add" />
            <input type="text" name="sku" placeholder="sku (a-z0-9-)" class="regular-text code" style="width:130px" required />
            <input type="text" name="title" placeholder="Title" class="regular-text" style="width:180px" required />
            <input type="text" name="price" placeholder="$0.05" class="small-text" required />
            <input type="url" name="url" placeholder="https://upstream.example/endpoint" class="regular-text code" style="width:280px" required />
            <?php submit_button('Add proxy product', 'secondary', 'submit', false); ?>
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
                <tr><td>Catalog (free, machine-readable)</td><td><code><?php echo esc_html(rest_url('x402/v1/catalog')); ?></code></td></tr>
                <tr><td>Ask (POST, per query)<?php echo get_option('x402_ask_enabled') ? '' : ' — disabled'; ?></td><td><code><?php echo esc_html(rest_url('x402/v1/ask')); ?></code></td></tr>
                <tr><td>Demo product — sample markdown, $0.01</td><td><code><?php echo esc_html(rest_url('x402/v1/demo')); ?></code></td></tr>
            </tbody>
        </table>

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