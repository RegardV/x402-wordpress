<?php
declare(strict_types=1);

use X402\Address;
use X402\Crypto;
use X402\Price;
use X402\Sanitizer;

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function (): void {
    add_menu_page('x402', 'x402', 'manage_options', 'x402', 'x402_render_admin_page', 'dashicons-money-alt');
});

add_action('admin_init', function (): void {
    $address_setting = fn (string $opt) => [
        'type'              => 'string',
        'sanitize_callback' => function ($value) use ($opt): string {
            $value = trim((string) $value);
            if ($value !== '' && !Address::is_valid($value)) {
                add_settings_error($opt, $opt, 'Not a valid address — expected 0x followed by 40 hex characters.');
                return (string) get_option($opt, '');
            }
            return $value;
        },
    ];
    register_setting('x402', 'x402_pay_to_testnet', $address_setting('x402_pay_to_testnet'));
    register_setting('x402', 'x402_pay_to_mainnet', $address_setting('x402_pay_to_mainnet'));
    register_setting('x402', 'x402_network', [
        'type'              => 'string',
        'sanitize_callback' => fn ($v): string => $v === X402_MAINNET ? X402_MAINNET : X402_TESTNET,
    ]);
    register_setting('x402', 'x402_cdp_key_id', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
    // CDP secret: encrypt at rest, and never round-trip the ciphertext through the form.
    register_setting('x402', 'x402_cdp_secret_input', [
        'type'              => 'string',
        'sanitize_callback' => function ($value): string {
            $value = trim((string) $value);
            if ($value !== '') {
                update_option('x402_cdp_secret_enc', Crypto::encrypt($value, wp_salt('secure_auth')), false);
            }
            return ''; // never store the raw input option itself
        },
    ]);
    register_setting('x402', 'x402_trust_proxy', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean']);
    // Ask products are now managed as collections (admin_post handlers), not options.
});

add_action('admin_notices', function (): void {
    if (x402_pay_to() === '' && current_user_can('manage_options')) {
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
    // Collection this import/edit belongs to (a named ask product).
    $slug = \X402\Collections::slug((string) ($_POST['collection'] ?? ''));
    if ($slug === '' || in_array($slug, X402_RESERVED_SKUS, true)) {
        set_transient('x402_import_report', ['summary' => 'collection needs a valid name (letters/numbers).', 'rejected' => []], 300);
        wp_safe_redirect(admin_url('admin.php?page=x402&tab=knowledge'));
        exit;
    }
    $price = trim((string) ($_POST['price'] ?? '$0.02'));
    try {
        Price::toMicro($price);
    } catch (InvalidArgumentException) {
        set_transient('x402_import_report', ['summary' => "invalid price for '$slug' — use e.g. \$0.02", 'rejected' => []], 300);
        wp_safe_redirect(admin_url('admin.php?page=x402&tab=knowledge'));
        exit;
    }
    $existing = x402_collection($slug) ?? [];
    x402_save_collection($slug, [
        'title'       => sanitize_text_field((string) ($_POST['title'] ?? $slug)) ?: $slug,
        'description' => mb_substr(sanitize_text_field((string) ($_POST['description'] ?? ($existing['description'] ?? ''))), 0, 250),
        'price'       => $price,
        'enabled'     => !empty($_POST['enabled']),
        'kind'        => $existing['kind'] ?? 'corpus',
    ]);

    // A zip is optional — this form also just edits a collection's ask/price.
    $file = $_FILES['corpus_zip'] ?? null;
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        set_transient('x402_import_report', x402_import_zip($file['tmp_name'], $slug), 300);
    } elseif ($file && ($file['error'] ?? 0) !== UPLOAD_ERR_NO_FILE) {
        set_transient('x402_import_report', ['summary' => "collection '$slug' saved, but upload failed — check upload_max_filesize (" . ini_get('upload_max_filesize') . ')', 'rejected' => []], 300);
    } else {
        set_transient('x402_import_report', ['summary' => "collection '$slug' saved.", 'rejected' => []], 300);
    }
    wp_safe_redirect(admin_url('admin.php?page=x402&tab=knowledge'));
    exit;
});

add_action('admin_post_x402_delete_collection', function (): void {
    if (!current_user_can('manage_options') || !check_admin_referer('x402_delete_collection')) {
        wp_die('forbidden');
    }
    $slug = \X402\Collections::slug((string) ($_POST['collection'] ?? ''));
    if ($slug !== '') {
        x402_delete_collection($slug);
        set_transient('x402_import_report', ['summary' => "collection '$slug' deleted (its chunks and files removed).", 'rejected' => []], 300);
    }
    wp_safe_redirect(admin_url('admin.php?page=x402&tab=knowledge'));
    exit;
});

/** @return array{summary:string, rejected:string[]} */
function x402_import_zip(string $zip_path, string $collection): array
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
        // Dedup within this collection only — two collections may share a filename.
        $existing = get_posts(['post_type' => 'x402_corpus', 'post_status' => 'private', 'title' => $name, 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_x402_collection', 'meta_value' => $collection]);
        $post_id  = $existing
            ? (wp_update_post(['ID' => $existing[0], 'post_content' => $clean]) ?: 0)
            : (int) wp_insert_post(['post_type' => 'x402_corpus', 'post_status' => 'private', 'post_title' => $name, 'post_content' => $clean]);
        if ($post_id <= 0) {
            $rejected[] = "$name — could not store";
            continue;
        }
        update_post_meta($post_id, '_x402_collection', $collection);
        $chunks += x402_index_content($collection, 'corpus', $post_id, $name, $clean);
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

const X402_TABS = [
    'network'   => 'Network',
    'knowledge' => 'Knowledge index',
    'endpoints' => 'Store endpoints',
    'sales'     => 'Sales',
    'proxy'     => 'Proxy endpoints',
];

function x402_render_admin_page(): void
{
    // First run, or an explicit relaunch / network switch, hands the page to the wizard.
    if (x402_wizard_active()) {
        x402_render_wizard();
        return;
    }
    $tab = isset($_GET['tab']) && isset(X402_TABS[$_GET['tab']]) ? (string) $_GET['tab'] : 'network';
    ?>
    <div class="wrap">
        <h1>x402 — sell to AI agents for USDC</h1>
        <?php settings_errors(); ?>
        <?php x402_network_switch_banner(); ?>
        <h2 class="nav-tab-wrapper">
            <?php foreach (X402_TABS as $slug => $label) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=x402&tab=' . $slug)); ?>"
                   class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </h2>
        <?php
        switch ($tab) {
            case 'knowledge': x402_tab_knowledge(); break;
            case 'endpoints': x402_tab_endpoints(); break;
            case 'sales':     x402_tab_sales();     break;
            case 'proxy':     x402_tab_proxy();     break;
            default:          x402_tab_network();
        }
        ?>
    </div>
    <?php
}

function x402_tab_network(): void
{
    $is_mainnet = x402_active_network() === X402_MAINNET;
    $has_cdp    = get_option('x402_cdp_key_id', '') !== '' && get_option('x402_cdp_secret_enc', '') !== '';
    ?>
    <p>Payments settle on-chain <strong>directly to your address</strong>. Receive addresses only — no private keys are ever stored in WordPress. Guided setup and switching happen in the <a href="<?php echo esc_url(admin_url('admin.php?page=x402&wizard=1&step=1')); ?>">setup wizard</a>; edit inline below.</p>
    <form method="post" action="options.php">
        <?php settings_fields('x402'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Active network</th>
                <td>
                    <label><input type="radio" name="x402_network" value="<?php echo esc_attr(X402_TESTNET); ?>" <?php checked(!$is_mainnet); ?> /> Testnet (Base Sepolia — facilitator x402.org, no keys)</label><br/>
                    <label><input type="radio" name="x402_network" value="<?php echo esc_attr(X402_MAINNET); ?>" <?php checked($is_mainnet); ?> /> Mainnet (Base — real USDC, needs CDP keys below)</label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="x402_pay_to_testnet">Testnet receive address</label></th>
                <td><input type="text" id="x402_pay_to_testnet" name="x402_pay_to_testnet" class="regular-text code" value="<?php echo esc_attr((string) get_option('x402_pay_to_testnet', (string) get_option('x402_pay_to', ''))); ?>" placeholder="0x…" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="x402_pay_to_mainnet">Mainnet receive address</label></th>
                <td><input type="text" id="x402_pay_to_mainnet" name="x402_pay_to_mainnet" class="regular-text code" value="<?php echo esc_attr((string) get_option('x402_pay_to_mainnet', '')); ?>" placeholder="0x…" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="x402_cdp_key_id">CDP API key ID (mainnet)</label></th>
                <td><input type="text" id="x402_cdp_key_id" name="x402_cdp_key_id" class="regular-text code" value="<?php echo esc_attr((string) get_option('x402_cdp_key_id', '')); ?>" placeholder="organizations/…/apiKeys/…" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="x402_cdp_secret_input">CDP API key secret (mainnet)</label></th>
                <td>
                    <input type="password" id="x402_cdp_secret_input" name="x402_cdp_secret_input" class="regular-text code" value="" placeholder="<?php echo $has_cdp ? '•••••• (stored — leave blank to keep)' : 'base64 Ed25519 secret'; ?>" autocomplete="off" />
                    <p class="description">Stored encrypted at rest. From the <a href="https://portal.cdp.coinbase.com" target="_blank" rel="noopener">Coinbase Developer Platform</a> — free, this is what authenticates mainnet settlement (funds still go to your wallet, never Coinbase's).</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Reverse proxy</th>
                <td><label><input type="checkbox" name="x402_trust_proxy" value="1" <?php checked(get_option('x402_trust_proxy')); ?> /> This site is behind a trusted proxy/CDN that sets <code>X-Forwarded-For</code> (used for per-visitor rate limiting <strong>and redelivery grants</strong> — leave off unless a real proxy strips inbound XFF, or a spoofed header could win free re-delivery of another buyer's purchase)</label></td>
            </tr>
        </table>
        <?php submit_button('Save'); ?>
    </form>
    <?php
}

function x402_tab_knowledge(): void
{
    $collections = x402_collections();
    $stats       = x402_collection_stats();
    ?>
    <p>Sell <strong>answers, not documents</strong>: each <em>collection</em> is its own ask product — a body of knowledge with its own pitch, price, and endpoint (<code>POST /x402/v1/ask/{slug}</code>). Import a vault to create one; agents pay per query and get cited passages.</p>

    <h2 class="title">Collections</h2>
    <?php if ($collections) : ?>
        <table class="widefat striped" style="max-width:960px;margin-bottom:12px">
            <thead><tr><th>Slug</th><th>Title</th><th>Ask description</th><th>Price</th><th>Docs / chunks</th><th>Live</th><th>Endpoint</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($collections as $slug => $c) :
                $s = $stats[$slug] ?? ['docs' => 0, 'chunks' => 0]; ?>
                <tr>
                    <td><code><?php echo esc_html((string) $slug); ?></code></td>
                    <td><?php echo esc_html((string) ($c['title'] ?? $slug)); ?><?php echo ($c['kind'] ?? '') === 'posts' ? ' <span class="description">(site posts)</span>' : ''; ?></td>
                    <td style="max-width:280px"><?php echo esc_html((string) ($c['description'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($c['price'] ?? '')); ?></td>
                    <td><?php echo (int) $s['docs']; ?> / <?php echo (int) $s['chunks']; ?></td>
                    <td><?php echo !empty($c['enabled']) ? '<span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>' : '—'; ?></td>
                    <td><code><?php echo esc_html(rest_url('x402/v1/ask/' . $slug)); ?></code></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete collection &quot;<?php echo esc_js((string) $slug); ?>&quot; and all its indexed content?');">
                            <?php wp_nonce_field('x402_delete_collection'); ?>
                            <input type="hidden" name="action" value="x402_delete_collection" />
                            <input type="hidden" name="collection" value="<?php echo esc_attr((string) $slug); ?>" />
                            <?php submit_button('Delete', 'link-delete', 'submit', false); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">This table is exactly what the <a href="<?php echo esc_url(rest_url('x402/v1/catalog')); ?>"><code>/catalog</code></a> endpoint advertises (enabled rows).</p>
    <?php else : ?>
        <p class="description">No collections yet. Import a vault below to create your first ask product.</p>
    <?php endif; ?>

    <h2 class="title">Import / update a collection</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('x402_import_corpus'); ?>
        <input type="hidden" name="action" value="x402_import_corpus" />
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="x402_col_name">Collection name</label></th>
                <td><input type="text" id="x402_col_name" name="collection" class="regular-text" placeholder="Kubernetes vault" required />
                    <p class="description">Becomes the URL slug. Re-using an existing slug <strong>replaces</strong> that collection's files (a fresh ingest).</p></td>
            </tr>
            <tr>
                <th scope="row"><label for="x402_col_title">Display title</label></th>
                <td><input type="text" id="x402_col_title" name="title" class="regular-text" placeholder="Ask my Kubernetes vault" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="x402_col_desc">Ask description (max 250)</label></th>
                <td><input type="text" id="x402_col_desc" name="description" class="large-text" maxlength="250" placeholder="What can agents ask this collection about?" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="x402_col_price">Price per query</label></th>
                <td><input type="text" id="x402_col_price" name="price" class="small-text" value="$0.02" /></td>
            </tr>
            <tr>
                <th scope="row">Live</th>
                <td><label><input type="checkbox" name="enabled" value="1" checked /> Sell this collection (list it in the catalog)</label></td>
            </tr>
            <tr>
                <th scope="row"><label for="x402_col_zip">Corpus zip</label></th>
                <td><input type="file" id="x402_col_zip" name="corpus_zip" accept=".zip" />
                    <p class="description">.md/.txt files, sanitized on import (dotfiles, binaries, key material rejected; frontmatter stripped) and stored <strong>privately</strong>. Optional — leave blank to only edit the pitch/price of an existing collection. Host upload limit: <?php echo esc_html((string) ini_get('upload_max_filesize')); ?>.</p></td>
            </tr>
        </table>
        <?php submit_button('Import / save collection'); ?>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('x402_reindex'); ?>
        <input type="hidden" name="action" value="x402_reindex" />
        <?php submit_button('Reindex everything', 'secondary', 'submit', false); ?>
    </form>
    <?php
}

function x402_tab_endpoints(): void
{
    $is_mainnet = x402_active_network() === X402_MAINNET;
    ?>
    <h2 class="title">Sell any post, page, or file</h2>
    <p>Edit any post, page, or media item — the <strong>“Sell to AI agents (x402)”</strong> box in the sidebar adds a price and puts it behind the paywall. Drop <code>[x402_catalog]</code> on a page to list everything for sale (styled by your theme).</p>

    <h2 class="title">Your store endpoints</h2>
    <?php if (x402_pay_to() === '') : ?>
        <p><span class="dashicons dashicons-warning" style="color:#dba617"></span> Not selling yet — set a receive address in the <strong>Network</strong> tab first.</p>
    <?php elseif ($is_mainnet && !x402_mainnet_ready()) : ?>
        <p><span class="dashicons dashicons-warning" style="color:#dba617"></span> Mainnet selected but CDP key ID/secret are missing — add them in the <strong>Network</strong> tab to settle real payments.</p>
    <?php else : ?>
        <p><span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> Live on <?php echo $is_mainnet ? 'mainnet' : 'testnet'; ?>. Agents hitting these URLs get an HTTP 402 challenge and can pay in USDC:</p>
    <?php endif; ?>
    <table class="widefat striped" style="max-width:960px">
        <thead><tr><th>Type</th><th>What</th><th>Price</th><th>URL</th></tr></thead>
        <tbody>
            <tr><td>catalog</td><td>Machine-readable product list (free)</td><td>—</td><td><code><?php echo esc_html(rest_url('x402/v1/catalog')); ?></code></td></tr>
            <tr><td>demo</td><td>Demo: sample markdown</td><td>$0.01</td><td><code><?php echo esc_html(rest_url('x402/v1/demo')); ?></code></td></tr>
            <?php foreach (x402_enabled_ask_products() as $p) : ?>
                <tr><td>ask</td><td><?php echo esc_html($p['title']); ?></td><td><?php echo esc_html($p['price']); ?></td><td><code><?php echo esc_html($p['url']); ?></code></td></tr>
            <?php endforeach; ?>
            <?php foreach (x402_all_item_products() as $p) : ?>
                <tr><td>item</td><td><?php echo esc_html($p['title']); ?></td><td><?php echo esc_html($p['price']); ?></td><td><code><?php echo esc_html($p['url']); ?></code></td></tr>
            <?php endforeach; ?>
            <?php foreach (x402_proxy_products() as $p) : ?>
                <tr><td>proxy</td><td><?php echo esc_html($p['title']); ?></td><td><?php echo esc_html($p['price']); ?></td><td><code><?php echo esc_html(rest_url('x402/v1/p/' . $p['sku'])); ?></code></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="description">This is your full public surface — the same set the <a href="<?php echo esc_url(rest_url('x402/v1/catalog')); ?>"><code>/catalog</code></a> endpoint returns to agents.</p>
    <?php
}

function x402_tab_sales(): void
{
    global $wpdb;
    $table  = $wpdb->prefix . 'x402_settlements';
    $sales  = $wpdb->get_results("SELECT tx_hash, payer, product_ref, amount_usdc_micro, network, created_at FROM $table ORDER BY id DESC LIMIT 20", ARRAY_A) ?: [];
    $totals = $wpdb->get_row("SELECT COUNT(*) AS n, COALESCE(SUM(amount_usdc_micro),0) AS micro FROM $table", ARRAY_A) ?: ['n' => 0, 'micro' => 0];
    $funnel = $wpdb->get_results("SELECT outcome, COUNT(*) AS n FROM {$wpdb->prefix}x402_requests GROUP BY outcome", OBJECT_K) ?: [];
    $n402   = (int) ($funnel['unpaid_402']->n ?? 0);
    $npaid  = (int) ($funnel['paid_200']->n ?? 0);
    $conv   = ($n402 + $npaid) > 0 ? round(100 * $npaid / ($n402 + $npaid), 1) : 0.0;
    ?>
    <p><strong><?php echo (int) $totals['n']; ?></strong> settled · <strong>$<?php echo esc_html(number_format(((int) $totals['micro']) / 1_000_000, 2)); ?></strong> USDC total · funnel: <strong><?php echo $n402; ?></strong> saw the price, <strong><?php echo $npaid; ?></strong> paid (<strong><?php echo esc_html((string) $conv); ?>%</strong> conversion)</p>
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
    <?php
}

function x402_tab_proxy(): void
{
    ?>
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
    <?php
}