<?php
/**
 * Publish overdue scheduled (future) posts whose post_date has passed.
 * Upload to WP root via deploy.php, hit once, self-deletes.
 */
@ini_set('display_errors', '0');
@set_time_limit(300);

$report = ['ok' => false, 'errors' => []];

try {
    require_once dirname(__FILE__) . '/wp-load.php';
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'wp-load failed', 'message' => $e->getMessage()]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$key = $_GET['key'] ?? '';
$secret = get_option('sourov_ai_secret_key', '0767044896thevenet_');
if (!$secret || !hash_equals((string) $secret, (string) $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
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
    $post_id = (int) $post_id;
    $post = get_post($post_id);
    if (!$post) {
        continue;
    }
    if ($dry_run) {
        $published[] = [
            'id' => $post_id,
            'title' => $post->post_title,
            'was_scheduled' => $post->post_date,
            'dry_run' => true,
        ];
        continue;
    }

    try {
        $update = wp_update_post([
            'ID' => $post_id,
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($update)) {
            $errors[] = ['id' => $post_id, 'error' => $update->get_error_message()];
            continue;
        }

        clean_post_cache($post_id);
        $fresh = get_post($post_id);
        if (!$fresh || $fresh->post_status !== 'publish') {
            $errors[] = ['id' => $post_id, 'error' => 'status still ' . ($fresh->post_status ?? 'missing')];
            continue;
        }

        $published[] = [
            'id' => $post_id,
            'title' => $fresh->post_title,
            'status' => $fresh->post_status,
            'link' => get_permalink($fresh),
        ];
    } catch (Throwable $e) {
        $errors[] = ['id' => $post_id, 'error' => $e->getMessage()];
    }
}

$counts = wp_count_posts('post');
$remaining_overdue = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_type = 'post' AND post_status = 'future' AND post_date_gmt <= %s",
    $now_gmt
));

$report = [
    'ok' => true,
    'dry_run' => $dry_run,
    'now_gmt' => $now_gmt,
    'published_count' => count($published),
    'remaining_overdue' => $remaining_overdue,
    'post_counts' => [
        'published' => (int) ($counts->publish ?? 0),
        'scheduled' => (int) ($counts->future ?? 0),
    ],
    'published' => $published,
    'errors' => $errors,
];

@unlink(__FILE__);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
