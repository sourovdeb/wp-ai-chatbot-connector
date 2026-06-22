<?php
/**
 * Self-chaining bulk draft scheduler for sourovdeb.com
 * Upload to WP root via deploy.php → schedule-all-drafts-job.php (NOT public_html/ prefix)
 * Start once: GET /schedule-all-drafts-job.php?key=SECRET&action=start
 * Auto-continues server-side until all drafts scheduled, then self-deletes.
 */
require_once dirname(__FILE__) . '/wp-load.php';

header('Content-Type: application/json; charset=utf-8');

$secret = get_option('sourov_ai_secret_key', '0767044896thevenet_');
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if (!$secret || !hash_equals((string) $secret, (string) $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

const SOUROV_JOB_OPTION = 'sourov_bulk_schedule_job_v1';
const SOUROV_JOB_LOG_OPTION = 'sourov_bulk_schedule_log_v1';

function sourov_job_infer_cats($title, $content) {
    $text = strtolower($title . ' ' . wp_strip_all_tags($content));
    if (preg_match('/english teaching|elt365|\belt\b|celta|tesol|grammar|classroom|lesson|learner/', $text)) return [9];
    if (preg_match('/mental health|anxiety|depress|wellbeing|therapy|stress|ptsd|bipolar|adhd/', $text)) return [582];
    if (preg_match('/philosophy|stoic|ethic|existential|sartre|bad faith/', $text)) return [581];
    if (preg_match('/career|professional|job|cv|resume|interview|reunion/', $text)) return [56];
    return [];
}

function sourov_job_apply_meta($post_id, $apply_category, $apply_tags, $title, $content) {
    if ($apply_category) {
        $cats = wp_get_post_categories($post_id);
        $cats = array_values(array_filter($cats, fn($id) => (int) $id !== 1));
        if (empty($cats)) {
            $cats = sourov_job_infer_cats($title, $content);
            if ($cats) wp_set_post_categories($post_id, $cats, false);
        }
    }
    if ($apply_tags) {
        $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
        if (empty($tags)) {
            $words = preg_split('/\s+/', strtolower($title));
            $tags = array_values(array_filter($words, fn($w) => strlen($w) > 3));
            $tags = array_slice($tags, 0, 5);
            if ($tags) wp_set_post_tags($post_id, $tags, false);
        }
    }
}

function sourov_job_counts() {
    $c = wp_count_posts('post');
    return [
        'draft' => (int) ($c->draft ?? 0),
        'scheduled' => (int) ($c->future ?? 0),
        'published' => (int) ($c->publish ?? 0),
    ];
}

function sourov_job_trigger_next($job) {
    $url = add_query_arg([
        'key' => $job['key'],
        'action' => 'tick',
        'token' => $job['token'],
    ], site_url('/schedule-all-drafts-job.php'));

    wp_remote_get($url, [
        'timeout' => 0.01,
        'blocking' => false,
        'sslverify' => true,
    ]);
}

$action = $_GET['action'] ?? 'status';
$job = get_option(SOUROV_JOB_OPTION, null);

if ($action === 'start') {
    $interval = max(1, (int) ($_GET['interval'] ?? 60));
    $start_hours = max(1, (int) ($_GET['start_hours'] ?? 1));
    $batch_size = max(10, min(100, (int) ($_GET['batch'] ?? 75)));
    $counts = sourov_job_counts();

    if ($counts['draft'] === 0) {
        echo json_encode(['ok' => true, 'message' => 'No drafts to schedule', 'counts' => $counts]);
        exit;
    }

    $token = wp_generate_password(16, false, false);
    $job = [
        'token' => $token,
        'key' => $key,
        'interval' => $interval,
        'start_hours' => $start_hours,
        'batch_size' => $batch_size,
        'offset' => 0,
        'total_scheduled' => 0,
        'started_at' => gmdate('c'),
        'last_tick' => null,
        'status' => 'running',
        'apply_category' => true,
        'apply_tags' => true,
    ];
    update_option(SOUROV_JOB_OPTION, $job, false);
    delete_option(SOUROV_JOB_LOG_OPTION);

    sourov_job_trigger_next($job);

    echo json_encode([
        'ok' => true,
        'message' => 'Bulk schedule job started — server will auto-continue in background',
        'job' => [
            'token' => $token,
            'drafts_total' => $counts['draft'],
            'interval_minutes' => $interval,
            'batch_size' => $batch_size,
            'eta_hours' => round($counts['draft'] * $interval / 60, 1),
        ],
        'poll' => add_query_arg(['key' => $key, 'action' => 'status'], site_url('/schedule-all-drafts-job.php')),
    ]);
    exit;
}

if ($action === 'status') {
    $counts = sourov_job_counts();
    $log = get_option(SOUROV_JOB_LOG_OPTION, []);
    echo json_encode([
        'ok' => true,
        'job' => $job,
        'counts' => $counts,
        'recent_log' => array_slice($log, -5),
    ]);
    exit;
}

if ($action === 'cancel') {
    delete_option(SOUROV_JOB_OPTION);
    echo json_encode(['ok' => true, 'message' => 'Job cancelled']);
    exit;
}

if ($action === 'tick') {
    if (!$job || ($job['status'] ?? '') !== 'running') {
        echo json_encode(['ok' => false, 'error' => 'no active job']);
        exit;
    }
    if (($_GET['token'] ?? '') !== ($job['token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'invalid token']);
        exit;
    }

    @set_time_limit(120);
    ignore_user_abort(true);

    $interval = (int) $job['interval'];
    $batch = (int) $job['batch_size'];
    $offset = (int) $job['offset'];
    $base = strtotime($job['started_at']) + ((int) $job['start_hours'] * 3600);
    $deadline = microtime(true) + 18.0;
    $processed = [];
    $n = 0;

    $query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'draft',
        'orderby' => 'date',
        'order' => 'ASC',
        'posts_per_page' => $batch,
        'offset' => 0,
        'fields' => 'ids',
    ]);

    foreach ($query->posts as $post_id) {
        if ($n >= $batch || microtime(true) >= $deadline) break;

        $post = get_post($post_id);
        if (!$post) continue;

        $global_index = $offset + $n;
        $slot = $base + ($global_index * $interval * 60);
        $gmt = gmdate('Y-m-d H:i:s', $slot);
        $local = get_date_from_gmt($gmt);

        wp_update_post([
            'ID' => $post_id,
            'post_status' => 'future',
            'post_date' => $local,
            'post_date_gmt' => $gmt,
            'edit_date' => true,
        ]);

        sourov_job_apply_meta($post_id, true, true, $post->post_title, $post->post_content);
        clean_post_cache($post_id);

        $processed[] = ['id' => $post_id, 'title' => $post->post_title, 'scheduled' => $local];
        $n++;
    }

    $job['offset'] = $offset + $n;
    $job['total_scheduled'] = (int) $job['total_scheduled'] + $n;
    $job['last_tick'] = gmdate('c');
    $remaining = sourov_job_counts()['draft'];

    $log = get_option(SOUROV_JOB_LOG_OPTION, []);
    $log[] = [
        'at' => $job['last_tick'],
        'batch' => $n,
        'total' => $job['total_scheduled'],
        'remaining_drafts' => $remaining,
    ];
    if (count($log) > 100) $log = array_slice($log, -100);
    update_option(SOUROV_JOB_LOG_OPTION, $log, false);

    if ($remaining > 0) {
        update_option(SOUROV_JOB_OPTION, $job, false);
        if ($n > 0) {
            sourov_job_trigger_next($job);
        }
        echo json_encode([
            'ok' => true,
            'status' => 'running',
            'batch_scheduled' => $n,
            'total_scheduled' => $job['total_scheduled'],
            'remaining_drafts' => $remaining,
            'sample' => array_slice($processed, 0, 3),
            'note' => $n === 0 ? 'tick processed zero posts; job kept alive for retry' : null,
        ]);
        exit;
    }

    $job['status'] = 'completed';
    $job['completed_at'] = gmdate('c');
    update_option(SOUROV_JOB_OPTION, $job, false);

    @unlink(__FILE__);

    echo json_encode([
        'ok' => true,
        'status' => 'completed',
        'total_scheduled' => $job['total_scheduled'],
        'counts' => sourov_job_counts(),
        'message' => 'All drafts scheduled. Job script removed itself.',
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action', 'actions' => ['start', 'tick', 'status', 'cancel']]);
