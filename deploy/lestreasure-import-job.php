<?php
/**
 * Import Les Treasure Hunters archive into sourovdeb.com / My Daily Journal
 * Upload to WP root via deploy.php → lestreasure-import-job.php
 * Scan:  GET ?key=SECRET&action=scan
 * Start: GET ?key=SECRET&action=start&batch=8
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

const LTH_JOB_OPTION = 'sourov_lestreasure_import_job_v1';
const LTH_LOG_OPTION = 'sourov_lestreasure_import_log_v1';
const LTH_CACHE_OPTION = 'sourov_lestreasure_import_cache_v1';
const LTH_PARENT_PAGE_ID = 39; // My Daily Journal
const LTH_SOURCE_SITE = 'lestreasurehunters.travel.blog';
const LTH_API = 'https://public-api.wordpress.com/rest/v1.1/sites/' . LTH_SOURCE_SITE;

const LTH_HUBS = [
    'journal-europe-travel' => [
        'title' => 'Europe Travel',
        'intro' => '<p>Travel stories, photos and videos from an Interrail journey across Europe — archived from Les Treasure Hunters.</p>',
        'themes' => ['Europe Travel Stories', 'Site Pages & Navigation'],
        'category' => 'Travel Journal',
    ],
    'journal-photo-software' => [
        'title' => 'Photography & Software',
        'intro' => '<p>Photo and video editing software reviews, tutorials and gear notes.</p>',
        'themes' => ['Photo & Video Editing / Gear Reviews'],
        'category' => 'Photography & Software',
    ],
    'journal-books-ideas' => [
        'title' => 'Books & Ideas',
        'intro' => '<p>Book summaries, philosophy, psychology and self-development writing.</p>',
        'themes' => ['Philosophy, Books & Self-Help'],
        'category' => 'Philosophy',
    ],
    'journal-creator-life' => [
        'title' => 'Creator & Life',
        'intro' => '<p>YouTube growth, creator business, personal stories and life updates.</p>',
        'themes' => ['YouTube & Creator Business', 'Personal & Life Stories'],
        'category' => 'Career & Professional Development',
    ],
];

const LTH_CATEGORY_MAP = [
    'Travel Journal' => 'travel-journal',
    'Photography & Software' => 'photography-software',
    'Philosophy' => 'philosophy',
    'Mental Health' => 'mental-health',
    'Career & Professional Development' => 'career-professional-development',
    'English Teaching' => 'english-teaching',
];

const LTH_THEME_KEYWORDS = [
    'Europe Travel Stories' => ['travel', 'europe', 'munich', 'berlin', 'amsterdam', 'interrail', 'zurich', 'rome', 'athens', 'portugal', 'switzerland', 'germany', 'greece', 'netherlands', 'montreux', 'krakow', 'hamburg', 'vienna', 'florence', 'naples', 'bari', 'ljubljana'],
    'Photo & Video Editing / Gear Reviews' => ['topaz', 'dxo', 'lightroom', 'photoshop', 'affinity', 'luminar', 'on1', 'silkypix', 'denoise', 'raw', 'camera', 'photography', 'filmulator', 'capture one', 'nikon', 'canon', 'gopro', 'lut', 'cinemagraph', 'video', 'filmmaking', 'gigapixel', 'sharpen', 'filmpack', 'software review', 'tutorial'],
    'YouTube & Creator Business' => ['youtube', 'subscriber', 'monetiz', 'affiliate', 'cyber', 'patreon', 'creator'],
    'Philosophy, Books & Self-Help' => ['philosophy', 'book', 'machiavelli', 'nietzsche', 'freud', 'darwin', 'marcus aurelius', 'prince', 'rules for life', 'influence', 'chomsky', 'strategy', 'mental health', 'depression', 'therapy', 'psychology', 'python', 'stock market', 'gamestop', 'summary'],
    'Personal & Life Stories' => ['french citizenship', 'marriage', 'soviet', 'india', 'bangladesh', 'privacy policy', 'chatgpt', 'apk'],
    'Site Pages & Navigation' => ['articles', 'travel-europe', 'travel-story', 'support', 'silkypix-developper', 'email-us'],
];

function lth_theme_for($title, $cats, $tags, $slug, $type) {
    if ($type === 'page') {
        $blob = strtolower($slug);
        if (strpos($blob, 'travel-story') !== false || strpos($blob, 'travel-europe') !== false || strpos($blob, 'montreux') !== false) {
            return 'Europe Travel Stories';
        }
        if (strpos($blob, 'silkypix') !== false) {
            return 'Photo & Video Editing / Gear Reviews';
        }
        if ($slug === 'articles') {
            return 'Europe Travel Stories';
        }
        return 'Site Pages & Navigation';
    }
    $blob = strtolower($title . ' ' . implode(' ', $cats) . ' ' . implode(' ', $tags) . ' ' . $slug);
    $scores = [];
    foreach (LTH_THEME_KEYWORDS as $theme => $kws) {
        $scores[$theme] = 0;
        foreach ($kws as $kw) {
            if (strpos($blob, $kw) !== false) {
                $scores[$theme]++;
            }
        }
    }
    arsort($scores);
    $best = key($scores);
    return ($scores[$best] > 0) ? $best : 'Europe Travel Stories';
}

function lth_hub_slug_for_theme($theme) {
    foreach (LTH_HUBS as $slug => $hub) {
        if (in_array($theme, $hub['themes'], true)) {
            return $slug;
        }
    }
    return 'journal-europe-travel';
}

function lth_ensure_category($name) {
    $slug = LTH_CATEGORY_MAP[$name] ?? sanitize_title($name);
    $term = get_term_by('slug', $slug, 'category');
    if ($term && !is_wp_error($term)) {
        return (int) $term->term_id;
    }
    $created = wp_insert_term($name, 'category', ['slug' => $slug]);
    if (is_wp_error($created)) {
        if ($created->get_error_code() === 'term_exists') {
            return (int) $created->get_error_data();
        }
        return 1;
    }
    return (int) $created['term_id'];
}

function lth_ensure_hub_pages() {
    $map = [];
    foreach (LTH_HUBS as $slug => $hub) {
        $existing = get_page_by_path('my-daily-journal/' . $slug, OBJECT, 'page');
        if ($existing) {
            $map[$slug] = (int) $existing->ID;
            continue;
        }
        $id = wp_insert_post([
            'post_type' => 'page',
            'post_title' => $hub['title'],
            'post_name' => $slug,
            'post_content' => $hub['intro'],
            'post_status' => 'publish',
            'post_parent' => LTH_PARENT_PAGE_ID,
            'post_author' => 1,
        ], true);
        if (!is_wp_error($id)) {
            $map[$slug] = (int) $id;
        }
    }
    return $map;
}

function lth_fetch_source_items() {
    $cached = get_option(LTH_CACHE_OPTION, null);
    if (is_array($cached) && !empty($cached['items']) && !empty($cached['fetched_at'])) {
        return $cached['items'];
    }

    $items = [];
    foreach (['post', 'page'] as $type) {
        for ($page = 1; $page <= 20; $page++) {
            $url = LTH_API . '/posts?type=' . $type . '&number=100&page=' . $page
                . '&fields=title,slug,date,type,categories,tags,content';
            $resp = wp_remote_get($url, ['timeout' => 90]);
            if (is_wp_error($resp)) {
                break;
            }
            $body = json_decode(wp_remote_retrieve_body($resp), true);
            $posts = $body['posts'] ?? [];
            if (empty($posts)) {
                break;
            }
            foreach ($posts as $p) {
                $cats = [];
                foreach ((array) ($p['categories'] ?? []) as $c) {
                    if (is_array($c) && !empty($c['name'])) {
                        $cats[] = $c['name'];
                    }
                }
                $tags = [];
                foreach ((array) ($p['tags'] ?? []) as $t) {
                    if (is_array($t) && !empty($t['name'])) {
                        $tags[] = $t['name'];
                    }
                }
                $title = wp_strip_all_tags(html_entity_decode($p['title'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $slug = sanitize_title($p['slug'] ?? '');
                $ptype = $p['type'] ?? $type;
                if ($slug === 'email-us' || $slug === 'privacy-policy') {
                    continue;
                }
                $theme = lth_theme_for($title, $cats, $tags, $slug, $ptype);
                $items[] = [
                    'title' => $title,
                    'slug' => $slug,
                    'type' => $ptype,
                    'date' => $p['date'] ?? '',
                    'content' => $p['content'] ?? '',
                    'categories' => $cats,
                    'tags' => $tags,
                    'theme' => $theme,
                    'hub_slug' => lth_hub_slug_for_theme($theme),
                ];
            }
            if (count($posts) < 100) {
                break;
            }
        }
    }

    $seen = [];
    $deduped = [];
    foreach ($items as $it) {
        $k = $it['type'] . ':' . $it['slug'];
        if (!isset($seen[$k])) {
            $seen[$k] = true;
            $deduped[] = $it;
        }
    }

    update_option(LTH_CACHE_OPTION, [
        'fetched_at' => gmdate('c'),
        'items' => $deduped,
        'count' => count($deduped),
    ], false);

    return $deduped;
}

function lth_fix_content($content, $title) {
    if (!$content) {
        return '<p>' . esc_html($title) . '</p>';
    }

    $content = preg_replace_callback('/<img\b[^>]*>/i', function ($m) {
        $tag = $m[0];
        $src = '';
        $alt = 'Image';
        if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $tag, $sm)) {
            $src = $sm[1];
        }
        if (preg_match('/\balt=["\']([^"\']*)["\']/i', $tag, $am)) {
            $alt = $am[1] !== '' ? $am[1] : 'Image';
        }
        if (preg_match('#(?:wp-content/uploads|lestreasurehunters|wordpress\.com/wp-content|i\d\.wp\.com)#i', $src)) {
            return '<p class="image-placeholder"><em>[Image unavailable: ' . esc_html($alt) . ']</em></p>';
        }
        return $tag;
    }, $content);

    $content = preg_replace('#https?://(?:www\.)?lestreasurehunters\.com[^"\s<]*#i', home_url('/my-daily-journal/'), $content);
    $content = preg_replace('#https?://lestreasurehunterstravel\.wordpress\.com[^"\s<]*#i', home_url('/my-daily-journal/'), $content);
    $content = preg_replace('#https?://lestreasurehunters\.travel\.blog[^"\s<]*#i', home_url('/my-daily-journal/'), $content);

    return $content;
}

function lth_apply_seo($post_id, $title, $content, $tags = []) {
    $plain = preg_replace('/\s+/', ' ', wp_strip_all_tags($content));
    $metadesc = mb_substr(trim($plain), 0, 155);
    $seo_title = mb_strlen($title) > 52 ? mb_substr($title, 0, 52) . '… | Sourov DEB' : $title . ' | Sourov DEB';
    $focuskw = implode(' ', array_slice(preg_split('/\s+/', wp_strip_all_tags($title)), 0, 4));

    update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
    update_post_meta($post_id, '_yoast_wpseo_metadesc', $metadesc);
    update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($focuskw));
    update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', '0');
    update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', '0');
    update_post_meta($post_id, '_meta_description', $metadesc);

    if (!empty($tags)) {
        wp_set_post_tags($post_id, array_slice(array_map('sanitize_text_field', $tags), 0, 8), false);
    }
}

function lth_already_imported($slug, $hub_page_id) {
    global $wpdb;
    $id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_name = %s AND post_parent = %d AND post_status IN ('publish','draft') LIMIT 1",
        $slug,
        $hub_page_id
    ));
    return $id ? (int) $id : 0;
}

function lth_import_item($item, $hubs) {
    $hub_slug = $item['hub_slug'];
    $hub_id = $hubs[$hub_slug] ?? 0;
    if (!$hub_id) {
        return ['skipped' => true, 'reason' => 'missing_hub', 'slug' => $item['slug']];
    }

    $existing = lth_already_imported($item['slug'], $hub_id);
    if ($existing) {
        return ['skipped' => true, 'reason' => 'exists', 'id' => $existing, 'slug' => $item['slug']];
    }

    $content = lth_fix_content($item['content'], $item['title']);
    $post_id = wp_insert_post([
        'post_type' => 'page',
        'post_title' => $item['title'],
        'post_name' => $item['slug'],
        'post_content' => $content,
        'post_status' => 'publish',
        'post_parent' => $hub_id,
        'post_author' => 1,
        'post_date' => $item['date'] ?: current_time('mysql'),
    ], true);

    if (is_wp_error($post_id)) {
        return ['error' => $post_id->get_error_message(), 'slug' => $item['slug']];
    }

    update_post_meta($post_id, '_lth_source_slug', $item['slug']);
    update_post_meta($post_id, '_lth_source_theme', $item['theme']);

    $hub_conf = LTH_HUBS[$hub_slug] ?? null;
    if ($hub_conf) {
        $cat_id = lth_ensure_category($hub_conf['category']);
        if ($cat_id > 1) {
            wp_set_post_categories($post_id, [$cat_id], false);
        }
    }

    $extra_tags = array_merge($item['tags'], $item['categories']);
    lth_apply_seo($post_id, $item['title'], $content, $extra_tags);

    return [
        'imported' => true,
        'id' => (int) $post_id,
        'slug' => $item['slug'],
        'hub' => $hub_slug,
        'url' => get_permalink($post_id),
    ];
}

function lth_update_parent_intro() {
    $parent = get_post(LTH_PARENT_PAGE_ID);
    if (!$parent) {
        return;
    }
    $intro = '<p>Archives from <strong>Les Treasure Hunters</strong> — travel diaries, photography software reviews, book notes and creator stories. Explore the sections below.</p>';
    if (strpos($parent->post_content, 'Les Treasure Hunters') === false) {
        wp_update_post([
            'ID' => LTH_PARENT_PAGE_ID,
            'post_content' => $intro . "\n\n" . $parent->post_content,
        ]);
    }
}

function lth_refresh_hub_indexes($hubs) {
    foreach ($hubs as $hub_slug => $hub_id) {
        $children = get_pages(['parent' => $hub_id, 'sort_column' => 'post_date', 'sort_order' => 'DESC']);
        if (empty($children)) {
            continue;
        }
        $hub = LTH_HUBS[$hub_slug] ?? ['intro' => '', 'title' => ''];
        $list = '<ul class="journal-archive-list">';
        foreach ($children as $child) {
            $list .= '<li><a href="' . esc_url(get_permalink($child->ID)) . '">' . esc_html($child->post_title) . '</a></li>';
        }
        $list .= '</ul>';
        wp_update_post([
            'ID' => $hub_id,
            'post_content' => $hub['intro'] . $list,
        ]);
    }
}

function lth_count_remaining($items, $hubs, $offset) {
    $remaining = 0;
    for ($i = $offset; $i < count($items); $i++) {
        $hub_id = $hubs[$items[$i]['hub_slug']] ?? 0;
        if ($hub_id && !lth_already_imported($items[$i]['slug'], $hub_id)) {
            $remaining++;
        }
    }
    return $remaining;
}

function lth_trigger_next($job) {
    $url = add_query_arg([
        'key' => $job['key'],
        'action' => 'tick',
        'token' => $job['token'],
    ], site_url('/lestreasure-import-job.php'));
    wp_remote_get($url, ['timeout' => 0.01, 'blocking' => false, 'sslverify' => true]);
}

$action = $_GET['action'] ?? 'scan';
$job = get_option(LTH_JOB_OPTION, null);

if ($action === 'scan') {
    $items = lth_fetch_source_items();
    $hubs = lth_ensure_hub_pages();
    $remaining = lth_count_remaining($items, $hubs, 0);
    echo json_encode([
        'ok' => true,
        'source' => LTH_SOURCE_SITE,
        'total_source_items' => count($items),
        'remaining_to_import' => $remaining,
        'hubs' => $hubs,
        'parent_page' => LTH_PARENT_PAGE_ID,
        'sample' => array_slice($items, 0, 5),
        'start_url' => add_query_arg(['key' => $key, 'action' => 'start', 'batch' => 8], site_url('/lestreasure-import-job.php')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'status') {
    $log = get_option(LTH_LOG_OPTION, []);
    $items = lth_fetch_source_items();
    $hubs = lth_ensure_hub_pages();
    $offset = (int) ($job['offset'] ?? 0);
    echo json_encode([
        'ok' => true,
        'job' => $job,
        'remaining' => lth_count_remaining($items, $hubs, $offset),
        'recent_log' => array_slice($log, -5),
        'child_pages_under_journal' => count(get_pages(['child_of' => LTH_PARENT_PAGE_ID])),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'cancel') {
    delete_option(LTH_JOB_OPTION);
    echo json_encode(['ok' => true, 'message' => 'Import job cancelled']);
    exit;
}

if ($action === 'start') {
    $batch = max(3, min(15, (int) ($_GET['batch'] ?? 8)));
    $items = lth_fetch_source_items();
    $hubs = lth_ensure_hub_pages();
    lth_update_parent_intro();
    $remaining = lth_count_remaining($items, $hubs, 0);
    if ($remaining === 0) {
        lth_refresh_hub_indexes($hubs);
        echo json_encode(['ok' => true, 'message' => 'All items already imported', 'remaining' => 0]);
        exit;
    }
    $token = wp_generate_password(16, false, false);
    $job = [
        'token' => $token,
        'key' => $key,
        'batch_size' => $batch,
        'offset' => 0,
        'processed' => 0,
        'imported' => 0,
        'skipped' => 0,
        'started_at' => gmdate('c'),
        'status' => 'running',
        'hubs' => $hubs,
    ];
    update_option(LTH_JOB_OPTION, $job, false);
    delete_option(LTH_LOG_OPTION);
    lth_trigger_next($job);
    echo json_encode([
        'ok' => true,
        'message' => 'Les Treasure Hunters import started',
        'remaining' => $remaining,
        'hubs' => $hubs,
        'poll' => add_query_arg(['key' => $key, 'action' => 'status'], site_url('/lestreasure-import-job.php')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

    $items = lth_fetch_source_items();
    $hubs = $job['hubs'] ?? lth_ensure_hub_pages();
    $batch = (int) $job['batch_size'];
    $offset = (int) $job['offset'];
    $results = [];
    $imported_n = 0;
    $skipped_n = 0;
    $deadline = microtime(true) + 25.0;

    while ($offset < count($items) && count($results) < $batch && microtime(true) < $deadline) {
        $item = $items[$offset];
        $offset++;
        $res = lth_import_item($item, $hubs);
        $results[] = $res;
        if (!empty($res['imported'])) {
            $imported_n++;
        } elseif (!empty($res['skipped'])) {
            $skipped_n++;
        }
    }

    $job['offset'] = $offset;
    $job['processed'] = (int) $job['processed'] + count($results);
    $job['imported'] = (int) $job['imported'] + $imported_n;
    $job['skipped'] = (int) $job['skipped'] + $skipped_n;
    $job['last_tick'] = gmdate('c');

    $remaining = lth_count_remaining($items, $hubs, $offset);

    $log = get_option(LTH_LOG_OPTION, []);
    $log[] = [
        'at' => $job['last_tick'],
        'batch' => count($results),
        'imported' => $imported_n,
        'skipped' => $skipped_n,
        'offset' => $offset,
        'remaining' => $remaining,
    ];
    if (count($log) > 80) {
        $log = array_slice($log, -80);
    }
    update_option(LTH_LOG_OPTION, $log, false);

    if ($remaining > 0) {
        update_option(LTH_JOB_OPTION, $job, false);
        lth_trigger_next($job);
        echo json_encode([
            'ok' => true,
            'status' => 'running',
            'batch_results' => array_slice($results, 0, 5),
            'imported_this_tick' => $imported_n,
            'total_imported' => $job['imported'],
            'remaining' => $remaining,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $job['status'] = 'completed';
    $job['completed_at'] = gmdate('c');
    update_option(LTH_JOB_OPTION, $job, false);
    lth_refresh_hub_indexes($hubs);
    flush_rewrite_rules(false);

    echo json_encode([
        'ok' => true,
        'status' => 'completed',
        'total_imported' => $job['imported'],
        'total_skipped' => $job['skipped'],
        'child_pages' => count(get_pages(['child_of' => LTH_PARENT_PAGE_ID])),
        'hubs' => $hubs,
        'message' => 'Import complete. Hub index pages updated.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action', 'actions' => ['scan', 'start', 'tick', 'status', 'cancel']]);
