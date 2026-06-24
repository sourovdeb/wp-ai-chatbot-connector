<?php
/**
 * Prune thin/broken My Daily Journal archive pages
 * GET ?key=SECRET&action=inspect
 * GET ?key=SECRET&action=apply
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

const JC_JOURNAL_PAGE_ID = 39;
const JC_HUB_PAGE_IDS = [2613, 2614, 2615, 2616];
const JC_MIN_LINES = 100;
const JC_MIN_WORDS = 80;

const JC_HUB_INTROS = [
    2613 => '<p>Travel stories, photos and videos from an Interrail journey across Europe — archived from Les Treasure Hunters.</p>',
    2614 => '<p>Photo and video editing software reviews, tutorials and gear notes.</p>',
    2615 => '<p>Book summaries, philosophy, psychology and self-development writing.</p>',
    2616 => '<p>YouTube growth, creator business, personal stories and life updates.</p>',
];

function jc_keep_page_ids() {
    return array_merge([JC_JOURNAL_PAGE_ID], JC_HUB_PAGE_IDS);
}

function jc_is_journal_descendant($page_id) {
    $page_id = (int) $page_id;
    if (in_array($page_id, jc_keep_page_ids(), true)) {
        return false;
    }
    $page = get_post($page_id);
    if (!$page || $page->post_type !== 'page') {
        return false;
    }
    if ((int) $page->post_parent === JC_JOURNAL_PAGE_ID) {
        return in_array($page_id, JC_HUB_PAGE_IDS, true) ? false : true;
    }
    $ancestors = array_map('intval', get_post_ancestors($page_id));
    return in_array(JC_JOURNAL_PAGE_ID, $ancestors, true);
}

function jc_count_content_lines($content, $text) {
    $raw_lines = preg_split('/\r\n|\r|\n/', (string) $content);
    $raw_nonempty = 0;
    foreach ($raw_lines as $line) {
        if (trim($line) !== '') {
            $raw_nonempty++;
        }
    }

    $text_lines = preg_split('/\r\n|\r|\n/', (string) $text);
    $text_nonempty = 0;
    foreach ($text_lines as $line) {
        if (trim($line) !== '') {
            $text_nonempty++;
        }
    }

    $paragraphs = (int) preg_match_all('/<p\b[^>]*>/i', (string) $content);
    $word_count = str_word_count((string) $text);
    $estimated = (int) ceil($word_count / 12);

    return max($raw_nonempty, $text_nonempty, $paragraphs, $estimated);
}

function jc_has_working_media($content) {
    if (preg_match('/<(video|audio|iframe|embed)\b/i', $content)) {
        return true;
    }

    if (preg_match_all('/<img\b[^>]*>/i', $content, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('/\bclass=["\'][^"\']*image-placeholder/i', $tag)) {
                continue;
            }
            if (!preg_match('/\bsrc=["\']([^"\']+)["\']/i', $tag, $src_match)) {
                continue;
            }
            $src = $src_match[1];
            if ($src === '' || stripos($src, 'data:image/svg') === 0) {
                continue;
            }
            if (preg_match('#(?:wp\.com|wordpress\.com|lestreasurehunters)#i', $src)) {
                continue;
            }
            if (preg_match('#(?:sourovdeb\.com/wp-content/uploads|/wp-content/uploads/)#i', $src)) {
                return true;
            }
            if (preg_match('#^https?://#i', $src)) {
                return true;
            }
        }
    }

    return false;
}

function jc_analyze_post($post) {
    $content = (string) $post->post_content;
    $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($content)));
    $word_count = str_word_count($text);
    $line_count = jc_count_content_lines($content, $text);
    $has_media = jc_has_working_media($content);
    $placeholder_count = (int) preg_match_all('/\[Image unavailable:/i', $content);
    $empty = ($word_count < JC_MIN_WORDS) || (mb_strlen($text) < 200);
    $thin = $line_count < JC_MIN_LINES;

    $reasons = [];
    if ($empty) {
        $reasons[] = 'empty_or_too_short';
    }
    if (!$has_media) {
        $reasons[] = 'no_working_media';
    }
    if ($thin) {
        $reasons[] = 'under_100_lines';
    }

    return [
        'id' => (int) $post->ID,
        'title' => $post->post_title,
        'slug' => $post->post_name,
        'url' => get_permalink($post->ID),
        'parent' => (int) $post->post_parent,
        'word_count' => $word_count,
        'line_count' => $line_count,
        'has_working_media' => $has_media,
        'placeholder_count' => $placeholder_count,
        'delete' => !empty($reasons),
        'reasons' => $reasons,
    ];
}

function jc_collect_journal_articles() {
    $all = get_pages([
        'child_of' => JC_JOURNAL_PAGE_ID,
        'post_status' => 'publish,draft,private',
        'sort_column' => 'post_title',
        'sort_order' => 'ASC',
    ]);
    $articles = [];
    foreach ($all as $page) {
        if (in_array((int) $page->ID, jc_keep_page_ids(), true)) {
            continue;
        }
        $articles[] = $page;
    }
    return $articles;
}

function jc_refresh_hub_indexes() {
    foreach (JC_HUB_PAGE_IDS as $hub_id) {
        $children = get_pages([
            'parent' => $hub_id,
            'post_status' => 'publish',
            'sort_column' => 'post_title',
            'sort_order' => 'ASC',
        ]);
        $intro = JC_HUB_INTROS[$hub_id] ?? '<p>Archive section.</p>';
        $list = '<ul class="journal-archive-list">';
        foreach ($children as $child) {
            $list .= '<li><a href="' . esc_url(get_permalink($child->ID)) . '">' . esc_html($child->post_title) . '</a></li>';
        }
        $list .= '</ul>';
        wp_update_post([
            'ID' => $hub_id,
            'post_content' => $intro . $list,
        ]);
    }
}

$action = $_GET['action'] ?? 'inspect';

if ($action === 'inspect') {
    $articles = jc_collect_journal_articles();
    $rows = [];
    $to_delete = [];
    foreach ($articles as $post) {
        $row = jc_analyze_post($post);
        $rows[] = $row;
        if ($row['delete']) {
            $to_delete[] = $row;
        }
    }

    echo json_encode([
        'ok' => true,
        'criteria' => [
            'min_lines' => JC_MIN_LINES,
            'min_words' => JC_MIN_WORDS,
            'delete_if_any' => ['empty_or_too_short', 'no_working_media', 'under_100_lines'],
        ],
        'total_journal_articles' => count($rows),
        'would_delete' => count($to_delete),
        'would_keep' => count($rows) - count($to_delete),
        'delete_preview' => array_slice($to_delete, 0, 40),
        'keep_preview' => array_slice(array_values(array_filter($rows, fn($r) => !$r['delete'])), 0, 15),
        'apply_url' => add_query_arg(['key' => $key, 'action' => 'apply'], home_url('/journal-cleanup-runner.php')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'apply') {
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);

    $articles = jc_collect_journal_articles();
    $deleted = [];
    $kept = 0;

    foreach ($articles as $post) {
        $row = jc_analyze_post($post);
        if (!$row['delete']) {
            $kept++;
            continue;
        }
        $ok = wp_delete_post((int) $post->ID, true);
        $deleted[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'slug' => $row['slug'],
            'reasons' => $row['reasons'],
            'deleted' => (bool) $ok,
        ];
    }

    jc_refresh_hub_indexes();

    echo json_encode([
        'ok' => true,
        'message' => 'Journal cleanup complete — removed thin/empty/media-less archive pages',
        'deleted_count' => count($deleted),
        'kept_count' => $kept,
        'deleted_sample' => array_slice($deleted, 0, 30),
        'remaining_articles' => count(jc_collect_journal_articles()),
        'hub_counts' => array_combine(
            JC_HUB_PAGE_IDS,
            array_map(fn($hub_id) => count(get_pages(['parent' => $hub_id, 'post_status' => 'publish'])), JC_HUB_PAGE_IDS)
        ),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action', 'actions' => ['inspect', 'apply']]);