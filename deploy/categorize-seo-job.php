<?php
/**
 * Self-chaining categorize + SEO job for sourovdeb.com
 * Upload to WP root → categorize-seo-job.php
 * Scan:  GET ?key=SECRET&action=scan
 * Start: GET ?key=SECRET&action=start&batch=40&use_ai=1
 */
require_once dirname(__FILE__) . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');

$secret = get_option('sourov_ai_secret_key', '0767044896thevenet_');
$key = $_GET['key'] ?? '';
if (!$secret || !hash_equals((string) $secret, (string) $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

const SOUROV_CAT_JOB = 'sourov_categorize_seo_job_v1';
const SOUROV_CAT_LOG = 'sourov_categorize_seo_log_v1';

const SOUROV_CATEGORIES = [
    9   => 'English Teaching',
    582 => 'Mental Health',
    581 => 'Philosophy',
    56  => 'Career & Professional Development',
];

function sourov_cat_get_openrouter_key() {
    $k = get_option('ai_chatbot_api_key', '');
    if ($k) return $k;
    return get_option('sourov_ai_openrouter_key', '');
}

function sourov_cat_has_real_category($post_id) {
    $cats = wp_get_post_categories($post_id);
    $cats = array_values(array_filter($cats, fn($id) => (int) $id !== 1));
    return !empty($cats);
}

function sourov_cat_infer_regex($title, $content) {
    $text = strtolower($title . ' ' . wp_strip_all_tags($content));
    $rules = [
        9 => '/english teaching|elt365|\belt\b|celta|tesol|grammar|classroom|lesson|learner|masterclass|pronunciation|vocabulary|speaking|writing skills|reading comprehension|teaching tip|day \d+|pedagog|curriculum|worksheet|efl|esl|language learning/',
        582 => '/mental health|anxiety|depress|wellbeing|well-being|therapy|stress|ptsd|bipolar|adhd|mindfulness|burnout|self-care|trauma|counsell/',
        581 => '/philosophy|stoic|ethic|existential|sartre|bad faith|epistemolog|metaphys|nietzsche|plato|aristotle|kant|heidegger|phenomenolog/',
        56 => '/career|professional development|job search|cv|resume|interview|linkedin|workplace|leadership skill|networking|promotion/',
    ];
    $scores = [];
    foreach ($rules as $cat_id => $pattern) {
        if (preg_match_all($pattern, $text, $m)) {
            $scores[$cat_id] = count($m[0]);
        }
    }
    if (empty($scores)) {
        return [];
    }
    arsort($scores);
    return [(int) key($scores)];
}

function sourov_cat_ai_classify($title, $content, $api_key) {
    if (!$api_key) {
        return null;
    }
    $cat_list = '';
    foreach (SOUROV_CATEGORIES as $id => $name) {
        $cat_list .= "- {$name} (id: {$id})\n";
    }
    $excerpt = mb_substr(wp_strip_all_tags($content), 0, 900);
    $prompt = "Classify this WordPress blog post. Reply with JSON only, no markdown:\n"
        . "{\"category_id\":9,\"focus_keyword\":\"short phrase\",\"meta_description\":\"max 155 chars\",\"tags\":[\"tag1\",\"tag2\",\"tag3\"]}\n\n"
        . "Valid category_id values:\n{$cat_list}\n"
        . "Title: {$title}\n\nExcerpt: {$excerpt}";

    $resp = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
        'timeout' => 45,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => home_url('/'),
            'X-Title' => 'Sourov Categorize SEO Job',
        ],
        'body' => wp_json_encode([
            'model' => 'meta-llama/llama-3.2-3b-instruct:free',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 350,
            'temperature' => 0.2,
        ]),
    ]);

    if (is_wp_error($resp)) {
        return null;
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $text = $body['choices'][0]['message']['content'] ?? '';
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed) && !empty($parsed['category_id'])) {
            $cid = (int) $parsed['category_id'];
            if (isset(SOUROV_CATEGORIES[$cid])) {
                return $parsed;
            }
        }
    }
    return null;
}

function sourov_cat_apply_seo($post_id, $title, $content, $ai = null) {
    $changes = [];

    $seo_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
    if (empty($seo_title)) {
        $seo_title = mb_strlen($title) > 52 ? mb_substr($title, 0, 52) . '… | Sourov DEB' : $title . ' | Sourov DEB';
        update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
        $changes[] = 'seo_title';
    }

    $metadesc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
    if (empty($metadesc)) {
        if (!empty($ai['meta_description'])) {
            $metadesc = mb_substr(sanitize_text_field($ai['meta_description']), 0, 155);
        } else {
            $plain = preg_replace('/\s+/', ' ', wp_strip_all_tags($content));
            $metadesc = mb_substr(trim($plain), 0, 155);
        }
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $metadesc);
        $changes[] = 'metadesc';
    }

    $focuskw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
    if (empty($focuskw)) {
        if (!empty($ai['focus_keyword'])) {
            $focuskw = sanitize_text_field($ai['focus_keyword']);
        } else {
            $words = preg_split('/\s+/', wp_strip_all_tags($title));
            $focuskw = implode(' ', array_slice(array_filter($words), 0, 4));
        }
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $focuskw);
        $changes[] = 'focuskw';
    }

    // Ensure indexable (Yoast)
    update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', '0');
    update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', '0');

    // Legacy meta_description field used by controller
    if (!get_post_meta($post_id, '_meta_description', true) && !empty($metadesc)) {
        update_post_meta($post_id, '_meta_description', $metadesc);
        $changes[] = 'legacy_meta';
    }

    return $changes;
}

function sourov_cat_apply_tags($post_id, $title, $ai = null) {
    $existing = wp_get_post_tags($post_id, ['fields' => 'names']);
    if (!empty($existing)) {
        return [];
    }
    if (!empty($ai['tags']) && is_array($ai['tags'])) {
        $tags = array_slice(array_map('sanitize_text_field', $ai['tags']), 0, 5);
    } else {
        $words = preg_split('/\s+/', strtolower($title));
        $stop = ['the', 'and', 'for', 'with', 'from', 'your', 'this', 'that', 'day', 'part'];
        $tags = array_values(array_filter($words, fn($w) => strlen($w) > 3 && !in_array($w, $stop, true)));
        $tags = array_slice($tags, 0, 5);
    }
    if ($tags) {
        wp_set_post_tags($post_id, $tags, false);
        return $tags;
    }
    return [];
}

function sourov_cat_audit_post($post_id) {
    $issues = [];
    if (!sourov_cat_has_real_category($post_id)) {
        $issues[] = 'uncategorized';
    }
    if (!get_post_meta($post_id, '_yoast_wpseo_metadesc', true)) {
        $issues[] = 'missing_metadesc';
    }
    if (!get_post_meta($post_id, '_yoast_wpseo_title', true)) {
        $issues[] = 'missing_seo_title';
    }
    if (!get_post_meta($post_id, '_yoast_wpseo_focuskw', true)) {
        $issues[] = 'missing_focuskw';
    }
    if (get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1') {
        $issues[] = 'noindex_set';
    }
    $tags = wp_get_post_tags($post_id);
    if (empty($tags)) {
        $issues[] = 'no_tags';
    }
    return $issues;
}

function sourov_cat_query_needs_work($offset, $limit, $statuses = ['future', 'publish', 'draft']) {
    global $wpdb;
    $status_in = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
         LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'category' AND tt.term_id != 1
         WHERE p.post_type = 'post' AND p.post_status IN ($status_in)
         GROUP BY p.ID
         HAVING COUNT(tt.term_id) = 0
         ORDER BY p.ID ASC
         LIMIT %d OFFSET %d",
        $limit,
        $offset
    ));
    return array_map('intval', $ids);
}

function sourov_cat_count_uncategorized($statuses = ['future', 'publish', 'draft']) {
    global $wpdb;
    $status_in = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM (
            SELECT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'category' AND tt.term_id != 1
            WHERE p.post_type = 'post' AND p.post_status IN ($status_in)
            GROUP BY p.ID
            HAVING COUNT(tt.term_id) = 0
        ) t"
    );
}

function sourov_cat_query_seo_needs_work($offset, $limit, $statuses = ['future', 'publish', 'draft']) {
    global $wpdb;
    $status_in = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} mdesc ON p.ID = mdesc.post_id AND mdesc.meta_key = '_yoast_wpseo_metadesc'
         LEFT JOIN {$wpdb->postmeta} mtitle ON p.ID = mtitle.post_id AND mtitle.meta_key = '_yoast_wpseo_title'
         LEFT JOIN {$wpdb->postmeta} mno ON p.ID = mno.post_id AND mno.meta_key = '_yoast_wpseo_meta-robots-noindex'
         WHERE p.post_type = 'post' AND p.post_status IN ($status_in)
           AND (mdesc.meta_id IS NULL OR mdesc.meta_value = '' OR mtitle.meta_id IS NULL OR mtitle.meta_value = '' OR mno.meta_value = '1')
         ORDER BY p.ID ASC
         LIMIT %d OFFSET %d",
        $limit,
        $offset
    ));
    return array_map('intval', $ids);
}

function sourov_cat_count_seo_needs_work($statuses = ['future', 'publish', 'draft']) {
    global $wpdb;
    $status_in = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";
    return (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} mdesc ON p.ID = mdesc.post_id AND mdesc.meta_key = '_yoast_wpseo_metadesc'
         LEFT JOIN {$wpdb->postmeta} mtitle ON p.ID = mtitle.post_id AND mtitle.meta_key = '_yoast_wpseo_title'
         LEFT JOIN {$wpdb->postmeta} mno ON p.ID = mno.post_id AND mno.meta_key = '_yoast_wpseo_meta-robots-noindex'
         WHERE p.post_type = 'post' AND p.post_status IN ($status_in)
           AND (mdesc.meta_id IS NULL OR mdesc.meta_value = '' OR mtitle.meta_id IS NULL OR mtitle.meta_value = '' OR mno.meta_value = '1')"
    );
}

function sourov_cat_count_needs_work($statuses = ['future', 'publish', 'draft']) {
    return sourov_cat_count_uncategorized($statuses) + sourov_cat_count_seo_needs_work($statuses);
}

function sourov_cat_trigger_next($job) {
    $url = add_query_arg([
        'key' => $job['key'],
        'action' => 'tick',
        'token' => $job['token'],
    ], site_url('/categorize-seo-job.php'));
    wp_remote_get($url, ['timeout' => 0.01, 'blocking' => false, 'sslverify' => true]);
}

$action = $_GET['action'] ?? 'scan';
$job = get_option(SOUROV_CAT_JOB, null);

if ($action === 'scan') {
    $uncat = sourov_cat_count_uncategorized();
    $seo_gap = sourov_cat_count_seo_needs_work();
    $sample_ids = array_slice(array_unique(array_merge(
        sourov_cat_query_needs_work(0, 3),
        sourov_cat_query_seo_needs_work(0, 3)
    )), 0, 5);
    $samples = [];
    foreach ($sample_ids as $pid) {
        $p = get_post($pid);
        if (!$p) continue;
        $samples[] = [
            'id' => $pid,
            'title' => $p->post_title,
            'status' => $p->post_status,
            'categories' => wp_get_post_categories($pid, ['fields' => 'names']),
            'issues' => sourov_cat_audit_post($pid),
        ];
    }
    echo json_encode([
        'ok' => true,
        'uncategorized_or_no_category' => $uncat,
        'seo_needs_work' => $seo_gap,
        'total_needs_work' => $uncat + $seo_gap,
        'categories_available' => SOUROV_CATEGORIES,
        'openrouter_key_set' => (bool) sourov_cat_get_openrouter_key(),
        'samples' => $samples,
        'start_url' => add_query_arg(['key' => $key, 'action' => 'start', 'batch' => 40, 'use_ai' => 1], site_url('/categorize-seo-job.php')),
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'status') {
    $log = get_option(SOUROV_CAT_LOG, []);
    echo json_encode([
        'ok' => true,
        'job' => $job,
        'remaining' => sourov_cat_count_needs_work(),
        'recent_log' => array_slice($log, -5),
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'cancel') {
    delete_option(SOUROV_CAT_JOB);
    echo json_encode(['ok' => true, 'message' => 'Categorize job cancelled']);
    exit;
}

if ($action === 'start') {
    $batch = max(5, min(60, (int) ($_GET['batch'] ?? 40)));
    $use_ai = !isset($_GET['use_ai']) || filter_var($_GET['use_ai'], FILTER_VALIDATE_BOOLEAN);
    $remaining = sourov_cat_count_needs_work();
    if ($remaining === 0) {
        echo json_encode(['ok' => true, 'message' => 'All posts already categorized', 'remaining' => 0]);
        exit;
    }
    $token = wp_generate_password(16, false, false);
    $job = [
        'token' => $token,
        'key' => $key,
        'batch_size' => $batch,
        'offset' => 0,
        'processed' => 0,
        'use_ai' => $use_ai && (bool) sourov_cat_get_openrouter_key(),
        'started_at' => gmdate('c'),
        'status' => 'running',
    ];
    update_option(SOUROV_CAT_JOB, $job, false);
    delete_option(SOUROV_CAT_LOG);
    sourov_cat_trigger_next($job);
    echo json_encode([
        'ok' => true,
        'message' => 'Categorize+SEO job started',
        'remaining' => $remaining,
        'use_ai' => $job['use_ai'],
        'poll' => add_query_arg(['key' => $key, 'action' => 'status'], site_url('/categorize-seo-job.php')),
    ], JSON_PRETTY_PRINT);
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

    $batch = (int) $job['batch_size'];
    $use_ai = !empty($job['use_ai']);
    $api_key = $use_ai ? sourov_cat_get_openrouter_key() : '';
    $deadline = microtime(true) + 22.0;
    $processed = [];
    $ai_calls = 0;

    // Phase 1: uncategorized, then phase 2: SEO gaps. Always offset 0.
    $ids = sourov_cat_query_needs_work(0, $batch);
    $phase = 'categorize';
    if (empty($ids)) {
        $ids = sourov_cat_query_seo_needs_work(0, $batch);
        $phase = 'seo';
    }
    foreach ($ids as $post_id) {
        if (microtime(true) >= $deadline) {
            break;
        }
        $post = get_post($post_id);
        if (!$post) {
            continue;
        }

        $ai = null;
        $cat_ids = [];
        if ($phase === 'categorize' || !sourov_cat_has_real_category($post_id)) {
            $cat_ids = sourov_cat_infer_regex($post->post_title, $post->post_content);
            if (empty($cat_ids) && $api_key && $ai_calls < 8) {
                $ai = sourov_cat_ai_classify($post->post_title, $post->post_content, $api_key);
                $ai_calls++;
                if ($ai && !empty($ai['category_id'])) {
                    $cat_ids = [(int) $ai['category_id']];
                }
            }
            if (empty($cat_ids)) {
                $cat_ids = [9];
            }
            wp_set_post_categories($post_id, $cat_ids, false);
        }
        $seo_changes = sourov_cat_apply_seo($post_id, $post->post_title, $post->post_content, $ai);
        $tags = sourov_cat_apply_tags($post_id, $post->post_title, $ai);
        clean_post_cache($post_id);

        $processed[] = [
            'id' => $post_id,
            'title' => mb_substr($post->post_title, 0, 60),
            'phase' => $phase,
            'category' => $cat_ids ? (SOUROV_CATEGORIES[$cat_ids[0]] ?? $cat_ids[0]) : 'unchanged',
            'seo' => $seo_changes,
            'tags' => $tags,
            'ai' => (bool) $ai,
        ];
    }

    $n = count($processed);
    $job['processed'] = (int) $job['processed'] + $n;
    $job['last_tick'] = gmdate('c');
    $remaining = sourov_cat_count_needs_work();

    $log = get_option(SOUROV_CAT_LOG, []);
    $log[] = [
        'at' => $job['last_tick'],
        'batch' => $n,
        'total' => $job['processed'],
        'remaining' => $remaining,
        'ai_calls' => $ai_calls,
    ];
    if (count($log) > 80) {
        $log = array_slice($log, -80);
    }
    update_option(SOUROV_CAT_LOG, $log, false);

    if ($remaining > 0) {
        update_option(SOUROV_CAT_JOB, $job, false);
        sourov_cat_trigger_next($job);
        echo json_encode([
            'ok' => true,
            'status' => 'running',
            'batch_processed' => $n,
            'total_processed' => $job['processed'],
            'remaining' => $remaining,
            'sample' => array_slice($processed, 0, 3),
            'note' => $n === 0 ? 'empty batch — will retry' : null,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $job['status'] = 'completed';
    $job['completed_at'] = gmdate('c');
    update_option(SOUROV_CAT_JOB, $job, false);

    $audit_sample = [];
    $all = get_posts(['post_type' => 'post', 'post_status' => ['future', 'publish'], 'posts_per_page' => 5, 'orderby' => 'rand']);
    foreach ($all as $p) {
        $audit_sample[] = ['id' => $p->ID, 'issues' => sourov_cat_audit_post($p->ID)];
    }

    echo json_encode([
        'ok' => true,
        'status' => 'completed',
        'total_processed' => $job['processed'],
        'remaining' => 0,
        'audit_sample' => $audit_sample,
        'message' => 'Categorize+SEO job finished. Re-run scan to verify; delete categorize-seo-job.php when done.',
    ], JSON_PRETTY_PRINT);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action', 'actions' => ['scan', 'start', 'tick', 'status', 'cancel']]);