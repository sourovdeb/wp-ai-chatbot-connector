<?php
/**
 * Publish overdue scheduled (future) posts whose post_date has passed.
 * Upload to WP root via deploy.php, hit once, self-deletes.
 */
require_once dirname(__FILE__) . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');

$key = $_GET['key'] ?? '';
$secret = get_option('sourov_ai_secret_key', '0767044896thevenet_');
if (!$secret || !hash_equals((string) $secret, (string) $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
$now_gmt = current_time('mysql', true);

global $wpdb;
$overdue_ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts}
     WHERE post_type = 'post' AND post_status = 'future' AND post_date_gmt <= %s
     ORDER BY post_date_gmt ASC
     LIMIT %d",
    $now_gmt,
    $limit
));

$published = [];
$errors = [];

foreach ($overdue_ids as $post_id) {
    $post = get_post((int) $post_id);
    if (!$post) {
        continue;
    }
    if ($dry_run) {
        $published[] = [
            'id' => (int) $post_id,
            'title' => $post->post_title,
            'was_scheduled' => $post->post_date,
            'dry_run' => true,
        ];
        continue;
    }

    // wp_publish_post() returns void — verify by post_status after
    wp_publish_post((int) $post_id);
    clean_post_cache((int) $post_id);
    $fresh = get_post((int) $post_id);

    if (!$fresh || $fresh->post_status !== 'publish') {
        $updated = wp_update_post([
            'ID' => (int) $post_id,
            'post_status' => 'publish',
        ], true);
        clean_post_cache((int) $post_id);
        $fresh = get_post((int) $post_id);
        if (is_wp_error($updated) || !$fresh || $fresh->post_status !== 'publish') {
            $errors[] = [
                'id' => (int) $post_id,
                'error' => is_wp_error($updated) ? $updated->get_error_message() : 'still not publish after update',
            ];
            continue;
        }
    }

    $published[] = [
        'id' => (int) $post_id,
        'title' => $fresh->post_title,
        'status' => $fresh->post_status,
        'link' => get_permalink($fresh),
    ];
}

$counts = wp_count_posts('post');
$remaining_overdue = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_type = 'post' AND post_status = 'future' AND post_date_gmt <= %s",
    $now_gmt
));

@unlink(__FILE__);
echo json_encode([
    'ok' => true,
    'dry_run' => $dry_run,
    'now_gmt' => $now_gmt,
    'published_count' => count($ublished),
    'remaining_overdue' => $remaining_overdue,
    'post_counts' => [
        'published' => (int) ($counts->publish ?? 0),
        'scheduled' => (int) ($counts->future ?? 0),
    ],
    'published' => $published,
    'errors' => $errors,
    'self_deleted' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
