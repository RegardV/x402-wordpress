<?php
declare(strict_types=1);

use X402\Chunker;

if (!defined('ABSPATH')) {
    exit;
}

const X402_DB_VERSION = 3;

/** Chunks live in their own table because FULLTEXT is the whole point (dbDelta can't manage FULLTEXT keys). */
function x402_chunks_table_sql(): string
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    return "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}x402_chunks (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        source varchar(10) NOT NULL,
        source_id bigint unsigned NOT NULL,
        source_name varchar(191) NOT NULL,
        heading varchar(191) NOT NULL,
        content mediumtext NOT NULL,
        PRIMARY KEY (id),
        KEY source (source, source_id),
        FULLTEXT KEY ft (heading, content)
    ) $charset";
}

/** Request funnel log — the 402→paid conversion view nobody else shows. */
function x402_requests_table_sql(): string
{
    global $wpdb;
    return "CREATE TABLE {$wpdb->prefix}x402_requests (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        product_ref varchar(191) NOT NULL,
        outcome varchar(20) NOT NULL,
        ip_hash char(64) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY product_outcome (product_ref, outcome),
        KEY created_at (created_at)
    ) {$wpdb->get_charset_collate()};";
}

function x402_upgrade_db(): void
{
    global $wpdb;
    if ((int) get_option('x402_db_version', 0) >= X402_DB_VERSION) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta(\X402\Settlements::table_sql($wpdb->prefix, $wpdb->get_charset_collate()));
    dbDelta(x402_requests_table_sql());
    $wpdb->query(x402_chunks_table_sql());
    update_option('x402_db_version', X402_DB_VERSION);
}
add_action('plugins_loaded', 'x402_upgrade_db');

/** Imported corpus files: private posts, invisible everywhere except the paid /ask lane. */
add_action('init', function (): void {
    register_post_type('x402_corpus', [
        'public'              => false,
        'show_ui'             => false,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'show_in_rest'        => false,
        'rewrite'             => false,
        'label'               => 'x402 corpus',
    ]);
});

/** Replace all chunks for one source document. */
function x402_index_content(string $source, int $source_id, string $source_name, string $content): int
{
    global $wpdb;
    $table = $wpdb->prefix . 'x402_chunks';
    $wpdb->delete($table, ['source' => $source, 'source_id' => $source_id]);
    $count = 0;
    foreach (Chunker::chunk($content) as $chunk) {
        $wpdb->insert($table, [
            'source'      => $source,
            'source_id'   => $source_id,
            'source_name' => mb_substr($source_name, 0, 191),
            'heading'     => mb_substr($chunk['heading'], 0, 191),
            'content'     => $chunk['text'],
        ]);
        $count++;
    }
    return $count;
}

function x402_deindex(string $source, int $source_id): void
{
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'x402_chunks', ['source' => $source, 'source_id' => $source_id]);
}

/** Keep the site-post index current (site indexing is a single admin toggle in Tier 2; per-post opt-in arrives with the Tier 1 meta box). */
add_action('save_post', function (int $post_id, WP_Post $post): void {
    if (wp_is_post_revision($post_id) || !in_array($post->post_type, ['post', 'page'], true)) {
        return;
    }
    if ($post->post_status === 'publish' && get_option('x402_ask_index_posts')) {
        x402_index_content('post', $post_id, $post->post_name, $post->post_content);
    } else {
        x402_deindex('post', $post_id);
    }
}, 10, 2);

add_action('deleted_post', function (int $post_id): void {
    x402_deindex('post', $post_id);
    x402_deindex('corpus', $post_id);
});

/** Full rebuild: all published posts/pages (if enabled) + every corpus document. */
function x402_reindex_all(): array
{
    $docs = 0;
    $chunks = 0;
    if (get_option('x402_ask_index_posts')) {
        foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => -1]) as $p) {
            $chunks += x402_index_content('post', $p->ID, $p->post_name, $p->post_content);
            $docs++;
        }
    }
    foreach (get_posts(['post_type' => 'x402_corpus', 'post_status' => 'private', 'numberposts' => -1]) as $p) {
        $chunks += x402_index_content('corpus', $p->ID, $p->post_title, $p->post_content);
        $docs++;
    }
    return ['docs' => $docs, 'chunks' => $chunks];
}