<?php
declare(strict_types=1);

use X402\Chunker;

if (!defined('ABSPATH')) {
    exit;
}

const X402_DB_VERSION = 4;

/** Chunks live in their own table because FULLTEXT is the whole point (dbDelta can't manage FULLTEXT keys). */
function x402_chunks_table_sql(): string
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    return "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}x402_chunks (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        collection varchar(64) NOT NULL DEFAULT '',
        source varchar(10) NOT NULL,
        source_id bigint unsigned NOT NULL,
        source_name varchar(191) NOT NULL,
        heading varchar(191) NOT NULL,
        content mediumtext NOT NULL,
        PRIMARY KEY (id),
        KEY collection (collection),
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
    $from = (int) get_option('x402_db_version', 0);
    if ($from >= X402_DB_VERSION) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta(\X402\Settlements::table_sql($wpdb->prefix, $wpdb->get_charset_collate()));
    dbDelta(x402_requests_table_sql());
    $wpdb->query(x402_chunks_table_sql());

    // v4: the single global ask becomes named collections.
    if ($from < 4) {
        $chunks = $wpdb->prefix . 'x402_chunks';
        if (!$wpdb->get_var("SHOW COLUMNS FROM $chunks LIKE 'collection'")) {
            $wpdb->query("ALTER TABLE $chunks ADD COLUMN collection varchar(64) NOT NULL DEFAULT '' AFTER id, ADD KEY collection (collection)");
        }
        $registry = [];
        // Existing imported corpus → a 'library' collection carrying the old ask settings.
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $chunks WHERE source = 'corpus'") > 0) {
            $wpdb->query("UPDATE $chunks SET collection = 'library' WHERE source = 'corpus' AND collection = ''");
            // Only stamp posts not already assigned — never clobber a later collection.
            foreach (get_posts(['post_type' => 'x402_corpus', 'post_status' => 'private', 'numberposts' => -1, 'fields' => 'ids', 'meta_query' => [['key' => '_x402_collection', 'compare' => 'NOT EXISTS']]]) as $id) {
                update_post_meta($id, '_x402_collection', 'library');
            }
            $registry['library'] = [
                'title'       => 'Imported knowledge',
                'description' => (string) get_option('x402_ask_description', 'Paid retrieval over an imported corpus. POST {"query": "..."} for cited passages.'),
                'price'       => (string) get_option('x402_ask_price', '$0.02'),
                'enabled'     => (bool) get_option('x402_ask_enabled'),
                'kind'        => 'corpus',
            ];
        }
        // Published-posts indexing → a reserved 'site' collection.
        if (get_option('x402_ask_index_posts')) {
            $wpdb->query("UPDATE $chunks SET collection = 'site' WHERE source = 'post' AND collection = ''");
            $registry['site'] = [
                'title'       => 'This site',
                'description' => 'Ask this site\'s published writing. POST {"query": "..."} for cited passages.',
                'price'       => (string) get_option('x402_ask_price', '$0.02'),
                'enabled'     => (bool) get_option('x402_ask_enabled'),
                'kind'        => 'posts',
            ];
        }
        if ($registry && !get_option('x402_collections')) {
            update_option('x402_collections', $registry);
        }
    }

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

/* ---------- collection registry ---------- */

/** @return array<string, array{title:string, description:string, price:string, enabled:bool, kind:string}> */
function x402_collections(): array
{
    $list = get_option('x402_collections', []);
    return is_array($list) ? $list : [];
}

function x402_collection(string $slug): ?array
{
    return x402_collections()[$slug] ?? null;
}

function x402_save_collection(string $slug, array $data): void
{
    $all = x402_collections();
    $all[$slug] = $data;
    update_option('x402_collections', $all);
}

/** Remove a collection everywhere: registry row, its chunks, and its private corpus posts. */
function x402_delete_collection(string $slug): void
{
    global $wpdb;
    $all = x402_collections();
    unset($all[$slug]);
    update_option('x402_collections', $all);
    foreach (get_posts(['post_type' => 'x402_corpus', 'post_status' => 'private', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_x402_collection', 'meta_value' => $slug]) as $id) {
        wp_delete_post((int) $id, true);
    }
    $wpdb->delete($wpdb->prefix . 'x402_chunks', ['collection' => $slug]);
}

/** Live doc/chunk counts per collection. @return array<string, array{docs:int, chunks:int}> */
function x402_collection_stats(): array
{
    global $wpdb;
    $rows = $wpdb->get_results("SELECT collection, COUNT(*) chunks, COUNT(DISTINCT CONCAT(source, source_id)) docs FROM {$wpdb->prefix}x402_chunks GROUP BY collection", OBJECT_K) ?: [];
    $out = [];
    foreach ($rows as $slug => $r) {
        $out[(string) $slug] = ['docs' => (int) $r->docs, 'chunks' => (int) $r->chunks];
    }
    return $out;
}

/* ---------- indexing ---------- */

/** Replace all chunks for one source document within a collection. */
function x402_index_content(string $collection, string $source, int $source_id, string $source_name, string $content): int
{
    global $wpdb;
    $table = $wpdb->prefix . 'x402_chunks';
    $wpdb->delete($table, ['source' => $source, 'source_id' => $source_id]);
    $count = 0;
    foreach (Chunker::chunk($content) as $chunk) {
        $wpdb->insert($table, [
            'collection'  => $collection,
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

/** Keep the reserved 'site' collection current when it exists and is fed by published posts. */
add_action('save_post', function (int $post_id, WP_Post $post): void {
    if (wp_is_post_revision($post_id) || !in_array($post->post_type, ['post', 'page'], true)) {
        return;
    }
    $site = x402_collection('site');
    if ($site && $post->post_status === 'publish') {
        x402_index_content('site', 'post', $post_id, $post->post_name, $post->post_content);
    } else {
        x402_deindex('post', $post_id);
    }
}, 10, 2);

add_action('deleted_post', function (int $post_id): void {
    x402_deindex('post', $post_id);
    x402_deindex('corpus', $post_id);
});

/** Rebuild every collection's chunks from its source documents. */
function x402_reindex_all(): array
{
    $docs = 0;
    $chunks = 0;
    if (x402_collection('site')) {
        foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => -1]) as $p) {
            $chunks += x402_index_content('site', 'post', $p->ID, $p->post_name, $p->post_content);
            $docs++;
        }
    }
    foreach (get_posts(['post_type' => 'x402_corpus', 'post_status' => 'private', 'numberposts' => -1]) as $p) {
        $collection = (string) (get_post_meta($p->ID, '_x402_collection', true) ?: 'library');
        $chunks += x402_index_content($collection, 'corpus', $p->ID, $p->post_title, $p->post_content);
        $docs++;
    }
    return ['docs' => $docs, 'chunks' => $chunks];
}