<?php
/**
 * One-shot WordPress schedule investigation — upload to WP root, hit once, self-deletes.
 * GET ?key=SECRET
 */
header('Content-Type: application/json; charset=utf-8');

if (($_GET['key'] ?? '') !== '0767044896thevenet_') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

require_once dirname(__FILE__) . '/wp-load.php';

global $wpdb;

$now_local = current_time('mysql');
$now_gmt = current_time('mysql', true);

$counts = wp_count_posts('post');

$overdue_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_type = 'post'
       AND post_status = 'future'
       AND post_date < %s",
    $now_local
));

$overdue_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT ID, post_title, post_date, post_date_gmt
     FROM {$wpdb->posts}
     WHERE post_type = 'post'
       AND post_status = 'future'
       AND post_date < %s
     ORDER BY post_date ASC
     LIMIT 10",
    $now_local
), ARRAY_A);

$overdue = [];
foreach ($overdue_rows as $row) {
    $overdue[] = [
        'id' => (int) $row['ID'],
        'title' => $row['post_title'],
        'post_date' => $row['post_date'],
        'post_date_gmt' => $row['post_date_gmt'],
    ];
}

$upcoming_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT ID, post_title, post_date, post_date_gmt
     FROM {$wpdb->posts}
     WHERE post_type = 'post'
       AND post_status = 'future'
       AND post_date >= %s
     ORDER BY post_date ASC
     LIMIT 5",
    $now_local
), ARRAY_A);

$upcoming = [];
foreach ($upcoming_rows as $row) {
    $upcoming[] = [
        'id' => (int) $row['ID'],
        'title' => $row['post_title'],
        'post_date' => $row['post_date'],
        'post_date_gmt' => $row['post_date_gmt'],
    ];
}

$comments_open = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_type = 'post'
       AND post_status = 'publish'
       AND comment_status = 'open'"
);

$comments_closed = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_type = 'post'
       AND post_status = 'publish'
       AND comment_status = 'closed'"
);

$zero_comment_sample = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM (
        SELECT p.ID
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->comments} c
          ON c.comment_post_ID = p.ID
         AND c.comment_approved NOT IN ('spam', 'trash')
        WHERE p.post_type = 'post'
          AND p.post_status = 'publish'
        GROUP BY p.ID
        HAVING COUNT(c.comment_ID) = 0
        LIMIT 50
     ) z"
);

$doing_cron = get_transient('doing_cron');
$cron_lock_exists = $doing_cron !== false;

$report = [
    'ok' => true,
    'site' => home_url('/'),
    'investigated_at' => [
        'local' => $now_local,
        'gmt' => $now_gmt,
    ],
    'timezone' => wp_timezone_string(),
    'gmt_offset' => (float) get_option('gmt_offset', 0),
    'post_counts' => [
        'published' => (int) ($counts->publish ?? 0),
        'draft' => (int) ($counts->draft ?? 0),
        'future_scheduled' => (int) ($counts->future ?? 0),
        'trash' => (int) ($counts->trash ?? 0),
    ],
    'scheduling' => [
        'overdue_count' => $overdue_count,
        'overdue_first_10' => $overdue,
        'upcoming_next_5' => $upcoming,
        'missed_schedule_detected' => $overdue_count > 0,
    ],
    'cron' => [
        'disable_wp_cron_defined' => defined('DISABLE_WP_CRON'),
        'disable_wp_cron' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'spawn_cron_available' => function_exists('spawn_cron'),
        'wp_cron_lock_transient_exists' => $cron_lock_exists,
        'wp_cron_lock_value' => $cron_lock_exists ? $doing_cron : null,
        'cron_array_scheduled_events' => is_array(_get_cron_array()) ? count(_get_cron_array()) : 0,
    ],
    'comments' => [
        'published_open' => $comments_open,
        'published_closed' => $comments_closed,
        'zero_comments_in_sample_50' => $zero_comment_sample,
    ],
    'self_deleted' => false,
];

$report['self_deleted'] = @unlink(__FILE__);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);