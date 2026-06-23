<?php
/**
 * One-time E2E self-test for AI Chatbot Connector v0.5.
 * Upload to public_html, visit once with deploy key, returns JSON (self-deletes).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
$key = $_GET['key'] ?? '';
if ($key !== '0767044896thevenet_') {
    http_response_code(403);
    exit(json_encode(['error' => 'forbidden']));
}
require_once('/home/u839078121/domains/sourovdeb.com/public_html/wp-load.php');

$out = [
    'timestamp' => gmdate('c'),
    'tests' => [],
];

$plugin_file = WP_PLUGIN_DIR . '/wp-ai-chatbot-connector/ai-chatbot-connector.php';
$out['plugin_file_bytes'] = is_file($plugin_file) ? filesize($plugin_file) : 0;
$out['plugin_active'] = function_exists('is_plugin_active')
    ? is_plugin_active('wp-ai-chatbot-connector/ai-chatbot-connector.php')
    : null;
$out['plugin_version'] = defined('AI_CHATBOT_VERSION') ? AI_CHATBOT_VERSION : 'not_loaded';

$out['options'] = [
    'provider' => get_option('ai_chatbot_provider', ''),
    'openrouter_key' => get_option('ai_chatbot_api_key', '') ? 'set' : 'missing',
    'google_key' => get_option('ai_chatbot_google_api_key', '') ? 'set' : 'missing',
    'fallbacks' => get_option('ai_chatbot_enable_fallbacks', ''),
    'google_model' => get_option('ai_chatbot_google_model', ''),
    'db_version' => get_option('ai_chatbot_db_version', ''),
];

$audit = null;
if (function_exists('ai_chatbot_site_audit_snapshot')) {
    $audit = ai_chatbot_site_audit_snapshot();
    $out['tests']['audit_snapshot'] = [
        'ok' => true,
        'preview' => substr($audit, 0, 500),
        'has_scheduled_warning' => strpos($audit, 'scheduled=') !== false,
    ];
} else {
    $out['tests']['audit_snapshot'] = ['ok' => false, 'error' => 'ai_chatbot_site_audit_snapshot missing'];
}

if (function_exists('ai_chatbot_call_api')) {
    $ping = ai_chatbot_call_api([['role' => 'user', 'content' => 'Reply with exactly: pong']], 20);
    $out['tests']['api_ping'] = $ping;

    if (!empty($audit)) {
        $audit_ai = ai_chatbot_call_api([
            ['role' => 'system', 'content' => function_exists('ai_chatbot_build_admin_system_prompt')
                ? ai_chatbot_build_admin_system_prompt()
                : 'WordPress assistant for sourovdeb.com'],
            ['role' => 'user', 'content' => "Using this live audit data, give a 3-bullet health summary. Do NOT ask for URL or credentials.\n\n" . $audit],
        ], 350);
        $out['tests']['audit_ai'] = [
            'ok' => !empty($audit_ai['ok']),
            'model_used' => $audit_ai['model_used'] ?? null,
            'reply_preview' => isset($audit_ai['reply']) ? substr($audit_ai['reply'], 0, 600) : null,
            'error' => $audit_ai['error'] ?? null,
        ];
    }
} else {
    $out['tests']['api_ping'] = ['ok' => false, 'error' => 'ai_chatbot_call_api missing'];
}

if (function_exists('ai_chatbot_visitor_search')) {
    $vis = ai_chatbot_visitor_search('english teaching');
    $out['tests']['visitor_search'] = [
        'ok' => !empty($vis['ok']),
        'reply_preview' => isset($vis['reply']) ? substr($vis['reply'], 0, 400) : null,
    ];
}

$out['self_deleted'] = @unlink(__FILE__);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
