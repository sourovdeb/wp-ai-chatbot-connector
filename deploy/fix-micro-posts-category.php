<?php
header('Content-Type: application/json');
if (($_GET['key'] ?? '') !== '0767044896thevenet_') {
    http_response_code(403);
    exit('{"error":"forbidden"}');
}
require_once('/home/u839078121/domains/sourovdeb.com/public_html/wp-load.php');

$out = ['actions' => []];
$slug = 'resources';
$name = 'Resources';
$term = get_term_by('slug', $slug, 'category');
if (!$term) {
    $created = wp_insert_term($name, 'category', ['slug' => $slug]);
    if (is_wp_error($created)) {
        $out['error'] = $created->get_error_message();
        echo json_encode($out);
        exit;
    }
    $cat_id = (int) $created['term_id'];
    $out['actions'][] = 'created_category';
} else {
    $cat_id = (int) $term->term_id;
    $out['actions'][] = 'found_category';
}
$out['category_id'] = $cat_id;

$post_ids = [2809, 2811];
foreach ($post_ids as $pid) {
    if (get_post($pid)) {
        wp_set_object_terms($pid, [$cat_id], 'category');
        $out['posts'][] = ['id' => $pid, 'category' => $cat_id, 'url' => get_permalink($pid)];
    }
}
$out['self_deleted'] = @unlink(__FILE__);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
