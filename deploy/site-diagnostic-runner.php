<?php
/**
 * One-shot site diagnostic — upload to WP root, hit once, self-deletes.
 */
require_once dirname(__FILE__) . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');

$key = $_GET['key'] ?? '';
$secret = get_option('sourov_ai_secret_key', '0767044896thevenet_');
if (!$secret || !hash_equals((string) $secret, (string) $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$counts = wp_count_posts('post');
$report = [
    'site' => home_url('/'),
    'wp_version' => get_bloginfo('version'),
    'php' => PHP_VERSION,
    'timezone' => wp_timezone_string(),
    'posts' => [
        'published' => (int) ($counts->publish ?? 0),
        'draft' => (int) ($counts->draft ?? 0),
        'scheduled' => (int) ($counts->future ?? 0),
        'trash' => (int) ($counts->trash ?? 0),
    ],
    'plugins' => [],
    'automation_settings' => get_option('sourov_automation_settings', []),
    'checks' => [],
];

$required = [
    'sourov-ai-controller.php' => 'Sourov AI Controller',
    'sourov-automation-agent.php' => 'Sourov Automation Agent',
    'sourov-diagnostic-agent.php' => 'Sourov Diagnostic Agent',
];
foreach ($required as $file => $label) {
    $path = WP_PLUGIN_DIR . '/' . $file;
    $report['plugins'][$file] = [
        'label' => $label,
        'exists' => file_exists($path),
        'size' => file_exists($path) ? filesize($path) : 0,
    ];
}

$report['checks']['automation_helper_defined'] = function_exists('sourov_automation_engine_status');
$report['checks']['site_kit_active'] = function_exists('is_plugin_active') && is_plugin_active('google-site-kit/google-site-kit.php');
$report['checks']['disable_wp_cron'] = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

$home = wp_remote_get(home_url('/'), ['timeout' => 20]);
if (!is_wp_error($home)) {
    $body = wp_remote_retrieve_body($home);
    $report['checks']['homepage_ok'] = wp_remote_retrieve_response_code($home) === 200;
    $report['checks']['bing_meta_on_home'] = stripos($body, 'msvalidate.01') !== false;
    $report['checks']['google_meta_on_home'] = stripos($body, 'google-site-verification') !== false;
}

$status = wp_remote_get(rest_url('sourov/v1/status'));
if (!is_wp_error($status)) {
    $report['sourov_api'] = json_decode(wp_remote_retrieve_body($status), true);
}

if (function_exists('sourov_automation_engine_status')) {
    $settings = get_option('sourov_automation_settings', []);
    $report['verification_status'] = [
        'google' => sourov_automation_engine_status('google', $settings),
        'bing' => sourov_automation_engine_status('bing', $settings),
        'yandex' => sourov_automation_engine_status('yandex', $settings),
    ];
}

$report['actions_recommended'] = [];
if (!$report['checks']['automation_helper_defined']) {
    $report['actions_recommended'][] = 'Redeploy fixed sourov-automation-agent.php (helper must be global scope)';
}
if ($report['posts']['scheduled'] > 0 && empty($report['checks']['disable_wp_cron'])) {
    $report['actions_recommended'][] = 'Add DISABLE_WP_CRON + Hostinger cron wget wp-cron.php every 5 min';
}
if ($report['checks']['site_kit_active']) {
    $report['actions_recommended'][] = 'Reconnect Google Site Kit (URL changed notice in wp-admin)';
}
if ($report['posts']['draft'] > 0) {
    $report['actions_recommended'][] = 'Run schedule-all-drafts-job.php?action=start for remaining drafts';
}

@unlink(__FILE__);
echo json_encode($report, JSON_PRETTY_PRINT);
