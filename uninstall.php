<?php
/**
 * Uninstall: remove everything the plugin stored — including imported corpus
 * content, which is the operator's private knowledge and must not linger.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}x402_settlements");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}x402_chunks");

// Imported corpus documents (private post type).
$corpus_ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'x402_corpus'));
foreach ($corpus_ids as $id) {
    wp_delete_post((int) $id, true);
}

// Options and rate-limit counters (transient variants covered by the LIKE).
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE 'x402\_%'
        OR option_name LIKE '\_transient\_x402\_%'
        OR option_name LIKE '\_transient\_timeout\_x402\_%'"
);