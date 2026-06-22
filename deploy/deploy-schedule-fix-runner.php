<?php
/**
 * One-shot: upload timezone-fixed controller + test schedule 3 drafts.
 * Hit: https://www.sourovdeb.com/deploy-schedule-fix-runner.php?key=KEY
 */
header('Content-Type: application/json');

$SECRET = '0767044896thevenet_';
if (($_GET['key'] ?? '') !== $SECRET) {
    http_response_code(403);
    exit(json_encode(['error' => 'forbidden']));
}

$wp_load = dirname(__FILE__) . '/wp-load.php';
if (!file_exists($wp_load)) {
    $wp_load = '/home/u839078121/domains/sourovdeb.com/public_html/wp-load.php';
}
if (!file_exists($wp_load)) {
    http_response_code(500);
    exit(json_encode(['error' => 'wp-load.php not found']));
}
require_once $wp_load;

$out = [];
$controller_path = WP_PLUGIN_DIR . '/sourov-ai-controller.php';
$github_raw = 'https://raw.githubusercontent.com/sourovdeb/wp-ai-chatbot-connector/main/deploy/sourov-ai-controller-v1.2.php';

$response = wp_remote_get($github_raw, ['timeout' => 60]);
if (is_wp_error($response)) {
    $out['upload'] = ['status' => 'error', 'message' => $response->get_error_message()];
} else {
    $body = wp_remote_retrieve_body($response);
    if (empty($body) || strpos($body, '<?php') !== 0) {
        $out['upload'] = ['status' => 'error', 'message' => 'invalid PHP from GitHub'];
    } else {
        $written = file_put_contents($controller_path, $body);
        $out['upload'] = [
            'status' => $written !== false ? 'ok' : 'error',
            'path' => 'wp-content/plugins/sourov-ai-controller.php',
            'bytes' => $written,
            'modified' => date('Y-m-d H:i:s'),
        ];
    }
}

$counts = wp_count_posts('post');
$out['status_before'] = [
    'online' => true,
    'version' => '1.2',
    'counts' => [
        'published' => (int) ($counts->publish ?? 0),
        'draft' => (int) ($counts->draft ?? 0),
        'scheduled' => (int) ($counts->future ?? 0),
    ],
];

// Inline schedule-drafts with timezone fix (gmdate/get_date_from_gmt)
$interval = 60;
$offset = 0;
$limit = 3;
$start_hours = 2;
$query = new WP_Query([
    'post_type' => 'post',
    'post_status' => 'draft',
    'orderby' => 'date',
    'order' => 'ASC',
    'posts_per_page' => $limit,
    'offset' => $offset,
]);
$base = time() + ($start_hours * 3600);
$results = [];
foreach ($query->posts as $i => $post) {
    $global_index = $offset + $i;
    $slot = $base + ($global_index * $interval * 60);
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
    $fresh = get_post($post->ID);
    $cats = wp_get_post_categories($fresh->ID, ['fields' => 'names']);
    $tags = wp_get_post_tags($fresh->ID, ['fields' => 'names']);
    $results[] = [
        'id' => $fresh->ID,
        'title' => $fresh->post_title,
        'status' => $fresh->post_status,
        'scheduled' => $fresh->post_status === 'future' ? $fresh->post_date : null,
        'categories' => $cats,
        'tags' => $tags,
        'url' => get_permalink($fresh->ID),
    ];
}
$out['test_schedule'] = [
    'scheduled' => count($results),
    'interval_minutes' => $interval,
    'offset' => $offset,
    'next_offset' => $offset + count($results),
    'remaining_drafts' => (int) wp_count_posts('post')->draft,
    'results' => $results,
];
$out['test_schedule_http'] = 200;

$counts = wp_count_posts('post');
$out['status_after'] = [
    'online' => true,
    'version' => '1.2',
    'counts' => [
        'published' => (int) ($counts->publish ?? 0),
        'draft' => (int) ($counts->draft ?? 0),
        'scheduled' => (int) ($counts->future ?? 0),
    ],
];

$sq = new WP_Query([
    'post_type' => 'post',
    'post_status' => 'future',
    'orderby' => 'post_date',
    'order' => 'ASC',
    'posts_per_page' => -1,
]);
$scheduled_posts = [];
foreach ($sq->posts as $p) {
    $scheduled_posts[] = [
        'id' => $p->ID,
        'title' => $p->post_title,
        'status' => $p->post_status,
        'scheduled' => $p->post_date,
        'categories' => wp_get_post_categories($p->ID, ['fields' => 'names']),
        'tags' => wp_get_post_tags($p->ID, ['fields' => 'names']),
        'url' => get_permalink($p->ID),
    ];
}
$out['scheduled_list'] = ['posts' => $scheduled_posts, 'count' => count($scheduled_posts)];

echo json_encode($out, JSON_PRETTY_PRINT);
@unlink(__FILE__);
