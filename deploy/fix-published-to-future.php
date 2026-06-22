<?php
/**
 * One-shot: move recently auto-published batch back to scheduled queue.
 * Targets posts published in the last 2 hours that were draft before batch run.
 */
require_once dirname(__FILE__) . '/wp-load.php';
header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
$secret = get_option('sourov_ai_secret_key', '0767044896thevenet_');
if ($key !== $secret) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$interval = max(1, (int) ($_GET['interval'] ?? 60));
$limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$start_hours = max(1, (int) ($_GET['start_hours'] ?? 1));

function sourov_fix_post_summary($post) {
    return [
        'id' => $post->ID,
        'title' => $post->post_title,
        'status' => $post->post_status,
        'scheduled' => $post->post_status === 'future' ? $post->post_date : null,
    ];
}

$since = gmdate('Y-m-d H:i:s', time() - 7200);
$query = new WP_Query([
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => $limit,
    'orderby' => 'date',
    'order' => 'ASC',
    'date_query' => [['column' => 'post_date_gmt', 'after' => $since]],
]);

$base = time() + ($start_hours * 3600);
$results = [];
foreach ($query->posts as $i => $post) {
    $slot = $base + ($i * $interval * 60);
    $gmt = gmdate('Y-m-d H:i:s', $slot);
    $local = get_date_from_gmt($gmt);
    wp_update_post([
        'ID' => $post->ID,
        'post_status' => 'future',
        'post_date' => $local,
        'post_date_gmt' => $gmt,
        'edit_date' => true,
    ]);
    clean_post_cache($post->ID);
    $results[] = sourov_fix_post_summary(get_post($post->ID));
}

@unlink(__FILE__);
echo json_encode([
    'fixed' => count($results),
    'remaining_drafts' => (int) wp_count_posts('post')->draft,
    'scheduled' => (int) wp_count_posts('post')->future,
    'results' => $results,
]);
