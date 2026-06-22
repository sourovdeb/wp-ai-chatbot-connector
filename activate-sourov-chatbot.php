<?php
/**
 * One-shot: activate Sourov AI Chatbot plugin. Self-deletes after run.
 * https://www.sourovdeb.com/activate-sourov-chatbot.php?key=0767044896thevenet_
 */
header('Content-Type: application/json');
$SECRET = '0767044896thevenet_';
if (($_GET['key'] ?? '') !== $SECRET) {
    http_response_code(403);
    exit(json_encode(['error' => 'forbidden']));
}

$wp_load = '/home/u839078121/domains/sourovdeb.com/public_html/wp-load.php';
if (!file_exists($wp_load)) {
    http_response_code(500);
    exit(json_encode(['error' => 'wp-load.php not found']));
}
require_once $wp_load;
if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$plugin = 'sourov-ai-chatbot/sourov-ai-chatbot.php';
$out = ['timestamp' => gmdate('c'), 'plugin' => $plugin];

if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin)) {
    $out['error'] = 'Plugin file missing';
    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

if (!is_plugin_active($plugin)) {
    $result = activate_plugin($plugin, '', false, true);
    if (is_wp_error($result)) {
        $out['activated'] = false;
        $out['error'] = $result->get_error_message();
    } else {
        $out['activated'] = true;
    }
} else {
    $out['activated'] = true;
    $out['already_active'] = true;
}

$out['active_plugins'] = array_values(get_option('active_plugins', []));
$out['self_deleted'] = @unlink(__FILE__);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
