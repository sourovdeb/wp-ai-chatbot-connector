<?php
/**
 * Plugin Name: AI Chatbot Connector (Ollama / Grok / OpenRouter)
 * Plugin URI: https://github.com/sourovdeb/wp-ai-chatbot-connector
 * Description: v0.3 - Multi-turn chat, floating widget, test connection, shared Ollama settings. OpenAI-compatible or AI Engine Ollama.
 * Version: 0.3.0
 * Author: Sourov Deb (via Grok)
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('AI_CHATBOT_VERSION', '0.3.0');

/* ── Settings ─────────────────────────────────────────────────────────── */

function ai_chatbot_register_settings() {
    register_setting('ai_chatbot_settings', 'ai_chatbot_provider', [
        'type' => 'string',
        'default' => 'openrouter',
        'sanitize_callback' => 'ai_chatbot_sanitize_provider',
    ]);
    register_setting('ai_chatbot_settings', 'ai_chatbot_endpoint');
    register_setting('ai_chatbot_settings', 'ai_chatbot_api_key');
    register_setting('ai_chatbot_settings', 'ai_chatbot_model');
    register_setting('ai_chatbot_settings', 'ai_chatbot_system_prompt');
    register_setting('ai_chatbot_settings', 'ai_chatbot_floating_widget');
    register_setting('ai_chatbot_settings', 'ai_chatbot_schedule_enabled');
    register_setting('ai_chatbot_settings', 'ai_chatbot_temperature');
    register_setting('ai_chatbot_settings', 'ai_chatbot_timeout');
    register_setting('ai_chatbot_settings', 'ai_chatbot_ignore_cf_access');
}

function ai_chatbot_sanitize_provider($value) {
    $allowed = ['shared_ollama', 'openrouter', 'xai', 'custom'];
    return in_array($value, $allowed, true) ? $value : 'openrouter';
}

add_action('admin_init', 'ai_chatbot_register_settings');

function ai_chatbot_admin_menu() {
    add_options_page(
        'AI Chatbot Connector',
        'AI Chatbot',
        'manage_options',
        'ai-chatbot-connector',
        'ai_chatbot_settings_page'
    );
}
add_action('admin_menu', 'ai_chatbot_admin_menu');

function ai_chatbot_has_shared_ollama() {
    return function_exists('aicu_get_ollama_settings');
}

function ai_chatbot_get_shared_ollama() {
    if (!ai_chatbot_has_shared_ollama()) {
        return null;
    }
    return aicu_get_ollama_settings();
}

function ai_chatbot_resolve_config() {
    $provider = get_option('ai_chatbot_provider', 'openrouter');
    $ignore_cf = get_option('ai_chatbot_ignore_cf_access', 'no') === 'yes';

    if ($provider === 'shared_ollama' && ai_chatbot_has_shared_ollama()) {
        $s = ai_chatbot_get_shared_ollama();
        $tunnel = rtrim($s['tunnel_url'] ?? '', '/');
        return [
            'provider' => 'shared_ollama',
            'endpoint' => $tunnel ? $tunnel . '/v1' : '',
            'api_key' => '',
            'model' => $s['model'] ?? 'qwen2.5:14b',
            'temperature' => floatval($s['temperature'] ?? 0.4),
            'timeout' => intval($s['timeout'] ?? 60),
            'cf_access_id' => $ignore_cf ? '' : ($s['cf_access_id'] ?? ''),
            'cf_access_secret' => $ignore_cf ? '' : ($s['cf_access_secret'] ?? ''),
            'tunnel_url' => $tunnel,
            'use_native_ollama' => true,
        ];
    }

    $defaults = [
        'openrouter' => ['endpoint' => 'https://openrouter.ai/api/v1', 'model' => 'meta-llama/llama-3.2-3b-instruct:free'],
        'xai' => ['endpoint' => 'https://api.x.ai/v1', 'model' => 'grok-2-latest'],
        'custom' => ['endpoint' => '', 'model' => 'llama3.2'],
    ];
    $d = $defaults[$provider] ?? $defaults['custom'];

    return [
        'provider' => $provider,
        'endpoint' => get_option('ai_chatbot_endpoint', $d['endpoint']),
        'api_key' => get_option('ai_chatbot_api_key', ''),
        'model' => get_option('ai_chatbot_model', $d['model']),
        'temperature' => floatval(get_option('ai_chatbot_temperature', 0.7)),
        'timeout' => intval(get_option('ai_chatbot_timeout', 60)),
        'cf_access_id' => '',
        'cf_access_secret' => '',
        'tunnel_url' => '',
        'use_native_ollama' => false,
    ];
}

function ai_chatbot_call_api($messages, $max_tokens = 600) {
    $cfg = ai_chatbot_resolve_config();

    if (empty($cfg['endpoint']) && empty($cfg['tunnel_url'])) {
        return ['ok' => false, 'error' => 'No API endpoint configured. Pick a provider and save settings.'];
    }

    if ($cfg['use_native_ollama'] && function_exists('aicu_ollama_chat')) {
        $result = aicu_ollama_chat($messages);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Ollama call failed'];
        }
        $content = is_array($result['parsed'])
            ? wp_json_encode($result['parsed'])
            : ($result['raw'] ?? 'OK');
        return ['ok' => true, 'reply' => $content];
    }

    $url = rtrim($cfg['endpoint'], '/') . '/chat/completions';
    $payload = [
        'model' => $cfg['model'],
        'messages' => $messages,
        'max_tokens' => $max_tokens,
        'temperature' => $cfg['temperature'],
    ];

    $headers = ['Content-Type' => 'application/json'];
    if (!empty($cfg['api_key'])) {
        $headers[] = 'Authorization: Bearer ' . $cfg['api_key'];
    }
    if (!empty($cfg['cf_access_id']) && !empty($cfg['cf_access_secret'])) {
        $headers[] = 'CF-Access-Client-Id: ' . $cfg['cf_access_id'];
        $headers[] = 'CF-Access-Client-Secret: ' . $cfg['cf_access_secret'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => wp_json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => max(10, $cfg['timeout']),
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['ok' => false, 'error' => 'Connection failed: ' . $curl_err];
    }
    if ($http_code !== 200) {
        return ['ok' => false, 'error' => 'HTTP ' . $http_code . ': ' . substr($response, 0, 500)];
    }

    $data = json_decode($response, true);
    $reply = $data['choices'][0]['message']['content'] ?? null;
    if ($reply === null) {
        return ['ok' => false, 'error' => 'Empty response from API'];
    }
    return ['ok' => true, 'reply' => $reply];
}

function ai_chatbot_run_diagnostics() {
    $cfg = ai_chatbot_resolve_config();
    $issues = [];
    $info = [];

    $floating = get_option('ai_chatbot_floating_widget', 'yes') === 'yes';
    $info[] = 'Floating widget: ' . ($floating ? 'ON (visitors see chat button)' : 'OFF');
    $info[] = 'Provider: ' . $cfg['provider'];
    $info[] = 'Model: ' . $cfg['model'];

    if ($cfg['provider'] === 'shared_ollama') {
        $tunnel = $cfg['tunnel_url'];
        $info[] = 'Tunnel URL: ' . ($tunnel ?: '(empty)');
        if (empty($tunnel)) {
            $issues[] = 'Tunnel URL is empty. Go to AI Engine → Settings (Ollama) and set your public HTTPS tunnel.';
        } elseif (preg_match('#^https?://127\.0\.0\.1#i', $tunnel) || preg_match('#^https?://localhost#i', $tunnel)) {
            $issues[] = 'Tunnel URL is ' . $tunnel . ' — WordPress runs on Hostinger and CANNOT reach your PC\'s localhost. Use a public Cloudflare Tunnel URL (https://ollama.yourdomain.com).';
        } elseif (!preg_match('#^https://#i', $tunnel)) {
            $issues[] = 'Tunnel URL should be HTTPS for production. HTTP may be blocked by Hostinger.';
        }
        if (!ai_chatbot_has_shared_ollama()) {
            $issues[] = 'AICU Idea Inbox plugin (shared Ollama settings) is not active.';
        }
    }

    if (empty($cfg['endpoint']) && $cfg['provider'] !== 'shared_ollama') {
        $issues[] = 'API endpoint is empty.';
    }

    if ($cfg['provider'] === 'openrouter' && empty($cfg['api_key'])) {
        $issues[] = 'OpenRouter API key is empty (some free models may still work).';
    }

    return ['issues' => $issues, 'info' => $info, 'config' => $cfg];
}

/* ── Admin settings page ──────────────────────────────────────────────── */

function ai_chatbot_settings_page() {
    if (!current_user_can('manage_options')) return;

    $provider = get_option('ai_chatbot_provider', 'openrouter');
    $floating = get_option('ai_chatbot_floating_widget', 'yes') === 'yes';
    $has_shared = ai_chatbot_has_shared_ollama();
    $diag = ai_chatbot_run_diagnostics();
    $test_result = isset($_GET['test_result']) ? sanitize_text_field(wp_unslash($_GET['test_result'])) : '';
    $test_ok = isset($_GET['test_ok']) ? $_GET['test_ok'] === '1' : null;
    ?>
    <div class="wrap">
        <h1>AI Chatbot Connector <small style="font-weight:normal;color:#666;">v<?php echo esc_html(AI_CHATBOT_VERSION); ?></small></h1>

        <div class="notice notice-info" style="padding:12px 16px;">
            <h2 style="margin:0 0 8px;font-size:15px;">How to activate the chat (3 steps)</h2>
            <ol style="margin:0 0 0 18px;line-height:1.8;">
                <li><strong>Configure provider below</strong> — pick OpenRouter (easiest) or Shared Ollama, then click <em>Save Changes</em>.</li>
                <li><strong>Enable Floating Widget</strong> — check the box, click <em>Save Changes</em> again.</li>
                <li><strong>Visit your site</strong> — open <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank"><?php echo esc_html(home_url('/')); ?></a> and click the blue <strong>chat button</strong> bottom-right.</li>
            </ol>
            <p style="margin:8px 0 0;">Status now: Floating widget is <strong><?php echo $floating ? 'ON' : 'OFF'; ?></strong>.
            <?php if (!$floating): ?><span style="color:#b32d2e;"> Turn it on below!</span><?php endif; ?>
            Shortcode alternative: <code>[ai_chatbot]</code> on any page.</p>
        </div>

        <?php if (!empty($diag['issues'])): ?>
        <div class="notice notice-warning"><p><strong>Diagnostics:</strong></p><ul style="margin:0 0 0 18px;">
            <?php foreach ($diag['issues'] as $issue): ?>
            <li><?php echo esc_html($issue); ?></li>
            <?php endforeach; ?>
        </ul></div>
        <?php endif; ?>

        <?php if ($test_result !== ''): ?>
        <div class="notice notice-<?php echo $test_ok ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo $test_ok ? 'Connection OK: ' : 'Connection failed: '; ?><?php echo esc_html($test_result); ?></p>
        </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('ai_chatbot_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ai_chatbot_provider">Provider</label></th>
                    <td>
                        <select name="ai_chatbot_provider" id="ai_chatbot_provider">
                            <option value="openrouter" <?php selected($provider, 'openrouter'); ?>>OpenRouter (cloud, easiest)</option>
                            <option value="shared_ollama" <?php selected($provider, 'shared_ollama'); ?> <?php disabled(!$has_shared); ?>>Shared Ollama (AI Engine settings)</option>
                            <option value="xai" <?php selected($provider, 'xai'); ?>>xAI Grok</option>
                            <option value="custom" <?php selected($provider, 'custom'); ?>>Custom OpenAI-compatible</option>
                        </select>
                        <?php if ($has_shared): ?>
                        <p class="description">Shared Ollama reads from <a href="<?php echo esc_url(admin_url('admin.php?page=aicu-ai-engine-settings')); ?>">AI Engine → Settings (Ollama)</a>. Configure tunnel, model, CF Access there once.</p>
                        <?php else: ?>
                        <p class="description" style="color:#b32d2e;">AICU Idea Inbox not active — Shared Ollama unavailable. Use OpenRouter or activate Idea Inbox.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="ai-chatbot-custom-fields">
                    <th scope="row"><label for="ai_chatbot_endpoint">API Endpoint</label></th>
                    <td>
                        <input type="url" name="ai_chatbot_endpoint" id="ai_chatbot_endpoint" value="<?php echo esc_attr(get_option('ai_chatbot_endpoint', 'https://openrouter.ai/api/v1')); ?>" class="regular-text" />
                        <p class="description">OpenRouter: <code>https://openrouter.ai/api/v1</code> | xAI: <code>https://api.x.ai/v1</code> | Ollama: <code>https://your-tunnel.example.com/v1</code></p>
                    </td>
                </tr>
                <tr class="ai-chatbot-custom-fields">
                    <th scope="row"><label for="ai_chatbot_api_key">API Key</label></th>
                    <td>
                        <input type="password" name="ai_chatbot_api_key" id="ai_chatbot_api_key" value="<?php echo esc_attr(get_option('ai_chatbot_api_key')); ?>" class="regular-text" autocomplete="new-password" />
                    </td>
                </tr>
                <tr class="ai-chatbot-custom-fields">
                    <th scope="row"><label for="ai_chatbot_model">Model</label></th>
                    <td>
                        <input type="text" name="ai_chatbot_model" id="ai_chatbot_model" value="<?php echo esc_attr(get_option('ai_chatbot_model', 'meta-llama/llama-3.2-3b-instruct:free')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_chatbot_ignore_cf_access">Ignore Cloudflare Access</label></th>
                    <td>
                        <input type="checkbox" name="ai_chatbot_ignore_cf_access" value="yes" <?php checked(get_option('ai_chatbot_ignore_cf_access', 'no'), 'yes'); ?> />
                        <span class="description">Skip CF Access headers when testing Ollama (use if tunnel has no Access policy).</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_chatbot_temperature">Temperature</label></th>
                    <td><input type="number" name="ai_chatbot_temperature" id="ai_chatbot_temperature" min="0" max="1" step="0.05" value="<?php echo esc_attr(get_option('ai_chatbot_temperature', '0.7')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_chatbot_timeout">Timeout (sec)</label></th>
                    <td><input type="number" name="ai_chatbot_timeout" id="ai_chatbot_timeout" min="10" max="180" value="<?php echo esc_attr(get_option('ai_chatbot_timeout', '60')); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">System Prompt</th>
                    <td><textarea name="ai_chatbot_system_prompt" rows="3" class="large-text"><?php echo esc_textarea(get_option('ai_chatbot_system_prompt', 'You are a helpful AI assistant for this WordPress site and publishing pipeline.')); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row">Floating Widget</th>
                    <td>
                        <label><input type="checkbox" name="ai_chatbot_floating_widget" value="yes" <?php checked(get_option('ai_chatbot_floating_widget', 'yes'), 'yes'); ?> />
                        Show chat button on every page (bottom-right)</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Post/Schedule from Chat</th>
                    <td>
                        <label><input type="checkbox" name="ai_chatbot_schedule_enabled" value="yes" <?php checked(get_option('ai_chatbot_schedule_enabled', 'no'), 'yes'); ?> />
                        Show draft/schedule buttons after AI replies</label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Changes'); ?>
        </form>

        <hr>
        <h2>Test connection</h2>
        <p>Sends a tiny ping from <strong>your Hostinger server</strong> to the configured AI endpoint.</p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ai_chatbot_test_connection'), 'ai_chatbot_test')); ?>">Run test ping</a>
            <?php if ($has_shared): ?>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=aicu-ai-engine-settings')); ?>">Open AI Engine Ollama Settings</a>
            <?php endif; ?>
        </p>

        <h3>Current config</h3>
        <ul style="list-style:disc;margin-left:18px;">
            <?php foreach ($diag['info'] as $line): ?>
            <li><code><?php echo esc_html($line); ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <script>
    (function(){
        var sel = document.getElementById('ai_chatbot_provider');
        var rows = document.querySelectorAll('.ai-chatbot-custom-fields');
        function toggle(){
            var show = sel.value === 'custom' || sel.value === 'openrouter' || sel.value === 'xai';
            rows.forEach(function(r){ r.style.display = show ? '' : 'none'; });
        }
        if(sel){ sel.addEventListener('change', toggle); toggle(); }
    })();
    </script>
    <?php
}

add_action('admin_post_ai_chatbot_test_connection', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('ai_chatbot_test');

    $pre = ai_chatbot_run_diagnostics();
    if (!empty($pre['issues'])) {
        $msg = implode(' | ', $pre['issues']);
        wp_safe_redirect(admin_url('options-general.php?page=ai-chatbot-connector&test_ok=0&test_result=' . rawurlencode($msg)));
        exit;
    }

    $system = get_option('ai_chatbot_system_prompt', 'You are a helpful assistant.');
    $result = ai_chatbot_call_api([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => 'Reply with exactly: pong'],
    ], 20);

    $cfg = ai_chatbot_resolve_config();
    if ($result['ok']) {
        $msg = 'Model ' . $cfg['model'] . ' replied: ' . substr($result['reply'], 0, 120);
        wp_safe_redirect(admin_url('options-general.php?page=ai-chatbot-connector&test_ok=1&test_result=' . rawurlencode($msg)));
    } else {
        wp_safe_redirect(admin_url('options-general.php?page=ai-chatbot-connector&test_ok=0&test_result=' . rawurlencode($result['error'])));
    }
    exit;
});

/* ── Frontend shortcode + widget ──────────────────────────────────────── */

function ai_chatbot_shortcode($atts) {
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('ai_chatbot_nonce');
    $schedule_enabled = get_option('ai_chatbot_schedule_enabled', 'no') === 'yes';
    ob_start();
    ?>
    <style>
        #ai-chatbot-container { font-family: system-ui, -apple-system, sans-serif; }
        .ai-chatbot-floating { position: fixed; bottom: 20px; right: 20px; z-index: 99999; box-shadow: 0 4px 20px rgba(0,0,0,0.25); max-width: 380px; width: 92%; border-radius: 12px; }
        .ai-chatbot-toggle { position: fixed; bottom: 20px; right: 20px; z-index: 100000; background: #0073aa; color: #fff; border: none; border-radius: 50%; width: 56px; height: 56px; font-size: 26px; cursor: pointer; box-shadow: 0 4px 12px rgba(0,115,170,0.4); display: flex; align-items: center; justify-content: center; }
        .ai-chatbot-toggle:hover { transform: scale(1.05); background: #005a87; }
    </style>
    <script>var aiChatbot = { ajax_url: '<?php echo esc_js($ajax_url); ?>', nonce: '<?php echo esc_js($nonce); ?>', scheduleEnabled: <?php echo $schedule_enabled ? 'true' : 'false'; ?> };</script>
    <button id="ai-chatbot-toggle" class="ai-chatbot-toggle" aria-label="Open AI Chat">&#x1F4AC;</button>
    <div id="ai-chatbot-container" class="ai-chatbot-floating" style="border:1px solid #ccc;overflow:hidden;background:#fff;display:none;">
        <div id="ai-chatbot-header" style="background:linear-gradient(90deg,#0073aa,#005a87);color:#fff;padding:12px 16px;font-weight:600;display:flex;justify-content:space-between;align-items:center;">
            <span>AI Assistant</span>
            <button id="ai-chatbot-close" style="background:rgba(255,255,255,0.2);border:none;color:#fff;font-size:20px;width:28px;height:28px;border-radius:50%;cursor:pointer;">&times;</button>
        </div>
        <div id="ai-chatbot-messages" style="height:320px;overflow-y:auto;padding:14px;background:#f8f9fa;font-size:14px;"></div>
        <div style="display:flex;padding:12px;background:#fff;border-top:1px solid #eee;gap:8px;">
            <input type="text" id="ai-chatbot-input" placeholder="Type a message..." style="flex:1;padding:10px 12px;border:1px solid #ccc;border-radius:8px;" />
            <button id="ai-chatbot-send" style="padding:10px 20px;background:#0073aa;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;">Send</button>
        </div>
    </div>
    <script>
    (function(){
        var toggleBtn=document.getElementById('ai-chatbot-toggle'),container=document.getElementById('ai-chatbot-container'),
            messagesDiv=document.getElementById('ai-chatbot-messages'),input=document.getElementById('ai-chatbot-input'),
            sendBtn=document.getElementById('ai-chatbot-send'),closeBtn=document.getElementById('ai-chatbot-close'),history=[];
        function toggleChat(show){var s=show!==undefined?show:(container.style.display==='none'||!container.style.display);container.style.display=s?'block':'none';if(s&&messagesDiv.children.length===0)addMsg('Hi! Ask me anything.','bot');}
        toggleBtn.onclick=function(){toggleChat();};if(closeBtn)closeBtn.onclick=function(){toggleChat(false);};
        function addMsg(t,who){var m=document.createElement('div');m.style.cssText='margin:6px 0;padding:10px 13px;border-radius:14px;max-width:82%;font-size:14px;white-space:pre-wrap;';m.style.cssText+=who==='user'?'background:#0073aa;color:#fff;margin-left:auto;':'background:#e9ecef;color:#212529;';m.textContent=t;messagesDiv.appendChild(m);messagesDiv.scrollTop=messagesDiv.scrollHeight;}
        sendBtn.onclick=function(){var t=input.value.trim();if(!t)return;addMsg(t,'user');history.push({role:'user',content:t});input.value='';
            fetch(aiChatbot.ajax_url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'ai_chatbot_query',nonce:aiChatbot.nonce,message:t,history:JSON.stringify(history)})})
            .then(function(r){return r.json();}).then(function(d){if(d.success){addMsg(d.data.reply,'bot');history.push({role:'assistant',content:d.data.reply});}else addMsg('Error: '+(d.data||'failed'),'bot');})
            .catch(function(){addMsg('Network error','bot');});};
        input.onkeypress=function(e){if(e.key==='Enter')sendBtn.onclick();};
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('ai_chatbot', 'ai_chatbot_shortcode');

function ai_chatbot_ajax_handler() {
    check_ajax_referer('ai_chatbot_nonce', 'nonce');
    if (!current_user_can('read')) wp_send_json_error('Permission denied');

    $message = sanitize_text_field($_POST['message'] ?? '');
    $history_json = stripslashes($_POST['history'] ?? '[]');
    $history = json_decode($history_json, true);
    if (!is_array($history)) $history = [];

    $system = get_option('ai_chatbot_system_prompt', 'You are a helpful AI assistant.');
    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($history as $turn) {
        if (isset($turn['role'], $turn['content'])) {
            $messages[] = ['role' => $turn['role'], 'content' => sanitize_text_field($turn['content'])];
        }
    }
    if (empty($history) || (end($history)['role'] ?? '') !== 'user') {
        $messages[] = ['role' => 'user', 'content' => $message];
    }

    $result = ai_chatbot_call_api($messages, 600);
    if (!$result['ok']) {
        wp_send_json_error($result['error']);
    }
    wp_send_json_success(['reply' => $result['reply']]);
}
add_action('wp_ajax_ai_chatbot_query', 'ai_chatbot_ajax_handler');
add_action('wp_ajax_nopriv_ai_chatbot_query', 'ai_chatbot_ajax_handler');

function ai_chatbot_create_post() {
    check_ajax_referer('ai_chatbot_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('edit_posts required');
    $title = sanitize_text_field($_POST['title'] ?? 'AI Draft');
    $content = wp_kses_post($_POST['content'] ?? '');
    $hours = intval($_POST['schedule_hours'] ?? 0);
    if (empty($content)) wp_send_json_error('No content');
    $post_data = ['post_title' => $title, 'post_content' => $content, 'post_status' => $hours > 0 ? 'future' : 'draft', 'post_type' => 'post', 'post_author' => get_current_user_id() ?: 1];
    if ($hours > 0) {
        $post_data['post_date'] = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
        $post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime("+{$hours} hours"));
    }
    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) wp_send_json_error($post_id->get_error_message());
    wp_send_json_success(['post_id' => $post_id, 'edit_link' => admin_url('post.php?post=' . $post_id . '&action=edit')]);
}
add_action('wp_ajax_ai_chatbot_create_post', 'ai_chatbot_create_post');

function ai_chatbot_floating_output() {
    if (get_option('ai_chatbot_floating_widget', 'yes') === 'yes') {
        echo do_shortcode('[ai_chatbot]');
    }
}
add_action('wp_footer', 'ai_chatbot_floating_output');

function ai_chatbot_activate() {
    if (!get_option('ai_chatbot_provider')) update_option('ai_chatbot_provider', 'openrouter');
    if (!get_option('ai_chatbot_endpoint')) update_option('ai_chatbot_endpoint', 'https://openrouter.ai/api/v1');
    if (!get_option('ai_chatbot_model')) update_option('ai_chatbot_model', 'meta-llama/llama-3.2-3b-instruct:free');
    if (!get_option('ai_chatbot_floating_widget')) update_option('ai_chatbot_floating_widget', 'yes');
}
register_activation_hook(__FILE__, 'ai_chatbot_activate');
