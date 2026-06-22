<?php
/**
 * One-time plugin manager runner — downloads plugin from GitHub, swaps AI plugins, self-deletes.
 * Hit: https://www.sourovdeb.com/plugin-manager-runner.php?key=0767044896thevenet_
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
    exit(json_encode(['error' => 'wp-load.php not found', 'path' => $wp_load]));
}

require_once $wp_load;

if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$out = [
    'timestamp' => gmdate('c'),
    'wp_load' => $wp_load,
];

// Capture before state
$active_before = get_option('active_plugins', []);
$out['before'] = [
    'active_plugins' => array_values($active_before),
    'active_count' => count($active_before),
];

// Always sync plugin from GitHub (force_sync)
$plugin_dir = WP_PLUGIN_DIR . '/wp-ai-chatbot-connector';
$plugin_file_rel = 'wp-ai-chatbot-connector/ai-chatbot-connector.php';
$plugin_file_abs = WP_PLUGIN_DIR . '/' . $plugin_file_rel;
$github_raw = 'https://raw.githubusercontent.com/sourovdeb/wp-ai-chatbot-connector/main/ai-chatbot-connector.php';

$install = [
    'attempted' => true,
    'force_sync' => true,
    'downloaded' => false,
    'bytes' => 0,
    'bytes_before' => file_exists($plugin_file_abs) ? filesize($plugin_file_abs) : 0,
    'error' => null,
];
if (!is_dir($plugin_dir)) {
    wp_mkdir_p($plugin_dir);
}
$response = wp_remote_get($github_raw, ['timeout' => 60]);
if (is_wp_error($response)) {
    $install['error'] = 'GitHub request failed: ' . $response->get_error_message();
} else {
    $body = wp_remote_retrieve_body($response);
    if (empty($body) || strpos($body, '<?php') !== 0) {
        $install['error'] = 'GitHub download failed or invalid PHP';
    } else {
        $written = file_put_contents($plugin_file_abs, $body);
        if ($written === false) {
            $install['error'] = 'file_put_contents failed';
        } else {
            $install['downloaded'] = true;
            $install['bytes'] = $written;
            $install['bytes_after'] = filesize($plugin_file_abs);
        }
    }
}
$out['plugin_install'] = $install;

// Plugins to deactivate
$deactivate = [
    'aicu-engine-reach.php',
    'wp-ai-bridge.php',
    'wp-ai-studio-bridge.php',
    'aicu-command-center.php',
    'aicu-ollama-uploader.php',
];

$deactivated = [];
foreach ($deactivate as $plugin_file) {
    if (is_plugin_active($plugin_file)) {
        deactivate_plugins($plugin_file, false, false);
        $deactivated[] = $plugin_file;
    }
}
$out['deactivated'] = $deactivated;

// Activate wp-ai-chatbot-connector
$activate_candidates = [
    'wp-ai-chatbot-connector/ai-chatbot-connector.php',
    'wp-ai-chatbot-connector/wp-ai-chatbot-connector.php',
];

$activated = null;
$activate_errors = [];
foreach ($activate_candidates as $candidate) {
    $full = WP_PLUGIN_DIR . '/' . $candidate;
    if (!file_exists($full)) {
        $activate_errors[] = "missing: {$candidate}";
        continue;
    }
    $result = activate_plugin($candidate, '', false, true);
    if (is_wp_error($result)) {
        $activate_errors[] = "{$candidate}: " . $result->get_error_message();
        continue;
    }
    $activated = $candidate;
    break;
}
$out['activated'] = $activated;
$out['activate_errors'] = $activate_errors;

// Delete junk files in plugins root
$plugins_root = WP_PLUGIN_DIR;
$junk_names = ['admin.css', 'admin.js', 'readme.txt', 'start.bat'];
$deleted = [];

foreach ($junk_names as $name) {
    $path = $plugins_root . '/' . $name;
    if (is_file($path) && @unlink($path)) {
        $deleted[] = $name;
    }
}

foreach (glob($plugins_root . '/*.zip') ?: [] as $zip) {
    if (is_file($zip) && @unlink($zip)) {
        $deleted[] = basename($zip);
    }
}
$out['deleted_junk'] = $deleted;

// Capture after state
$active_after = get_option('active_plugins', []);
$out['after'] = [
    'active_plugins' => array_values($active_after),
    'active_count' => count($active_after),
];

$out['self_deleted'] = @unlink(__FILE__);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
