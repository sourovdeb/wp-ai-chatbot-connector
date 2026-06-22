<?php
/**
 * Plugin Name: AI Chatbot Connector (Ollama / Grok / OpenRouter)
 * Plugin URI: https://github.com/sourovdeb/wp-ai-chatbot-connector
 * Description: v0.4 - Dual mode: visitor site search/nav + admin AI assistant (frontend + wp-admin).
 * Version: 0.4.0
 * Author: Sourov Deb (via Grok)
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('AI_CHATBOT_VERSION', '0.4.0');

/* ── Capabilities ─────────────────────────────────────────────────────── */

function ai_chatbot_is_admin_user() {
    return is_user_logged_in() && current_user_can('edit_posts');
}

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
    register_setting('ai_chatbot_settings', 'ai_chatbot_visitor_enabled');
    register_setting('ai_chatbot_settings', 'ai_chatbot_admin_frontend_enabled');
    register_setting('ai_chatbot_settings', 'ai_chatbot_admin_wpadmin_enabled');
    register_setting('ai_chatbot_settings', 'ai_chatbot_schedule_enabled');
    register_setting('ai_chatbot_settings', 'ai_chatbot_temperature');
    register_setting('ai_chatbot_settings', 'ai_chatbot_timeout');
    register_setting('ai_chatbot_settings', 'ai_chatbot_ignore_cf_access');
}

function ai_chatbot_sanitize_provider($value) {
    $allowed = ['shared_ollama', 'openrouter', 'xai', 'custom'];
    return in_array($value, $allowed, true) ? $value : 'openrouter';
}

function ai_chatbot_opt_on($key, $default = 'yes') {
    return get_option($key, $default) === 'yes';
}

add_action('admin_init', 'ai_chatbot_register_settings');

function ai_chatbot_admin_menu() {
    add_options_page('AI Chatbot Connector', 'AI Chatbot', 'manage_options', 'ai-chatbot-connector', 'ai_chatbot_settings_page');
}
add_action('admin_menu', 'ai_chatbot_admin_menu');

function ai_chatbot_has_shared_ollama() {
    return function_exists('aicu_get_ollama_settings');
}

function ai_chatbot_get_shared_ollama() {
    return ai_chatbot_has_shared_ollama() ? aicu_get_ollama_settings() : null;
}

function ai_chatbot_resolve_config() {
    $provider = get_option('ai_chatbot_provider', 'openrouter');
    $ignore_cf = ai_chatbot_opt_on('ai_chatbot_ignore_cf_access', 'no');

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
        return ['ok' => false, 'error' => 'No API endpoint configured.'];
    }

    if ($cfg['use_native_ollama'] && function_exists('aicu_ollama_chat')) {
        $result = aicu_ollama_chat($messages);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Ollama call failed'];
        }
        $content = is_array($result['parsed']) ? wp_json_encode($result['parsed']) : ($result['raw'] ?? 'OK');
        return ['ok' => true, 'reply' => $content];
    }

    $url = rtrim($cfg['endpoint'], '/') . '/chat/completions';
    $payload = ['model' => $cfg['model'], 'messages' => $messages, 'max_tokens' => $max_tokens, 'temperature' => $cfg['temperature']];
    $headers = ['Content-Type: application/json'];
    if (!empty($cfg['api_key'])) $headers[] = 'Authorization: Bearer ' . $cfg['api_key'];
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

    if ($curl_err) return ['ok' => false, 'error' => 'Connection failed: ' . $curl_err];
    if ($http_code !== 200) return ['ok' => false, 'error' => 'HTTP ' . $http_code . ': ' . substr((string) $response, 0, 500)];

    $data = json_decode($response, true);
    $reply = $data['choices'][0]['message']['content'] ?? null;
    return $reply === null ? ['ok' => false, 'error' => 'Empty response'] : ['ok' => true, 'reply' => $reply];
}

/* ── Visitor: site search & navigation (no external AI) ───────────────── */

function ai_chatbot_visitor_sections() {
    $sections = [];
    $cats = get_categories(['hide_empty' => true, 'number' => 20]);
    foreach ($cats as $cat) {
        $sections[] = ['label' => $cat->name, 'url' => get_category_link($cat->term_id), 'slug' => $cat->slug];
    }
    $sections[] = ['label' => 'Home', 'url' => home_url('/'), 'slug' => 'home'];
    return $sections;
}

function ai_chatbot_visitor_match_section($query) {
    $q = strtolower($query);
    $map = [
        'english' => ['english-teaching', 'english teaching'],
        'teach' => ['english-teaching', 'english teaching'],
        'philosophy' => ['philosophy-mental-health', 'philosophy', 'mental health'],
        'mental' => ['philosophy-mental-health', 'mental health'],
        'resource' => ['resources'],
        'tool' => ['resources'],
        'affiliate' => ['resources'],
        'about' => ['about'],
        'contact' => ['contact'],
        'home' => ['home'],
    ];
    foreach ($map as $needle => $slugs) {
        if (strpos($q, $needle) !== false) {
            foreach (ai_chatbot_visitor_sections() as $sec) {
                if (in_array($sec['slug'], $slugs, true) || stripos($sec['label'], $needle) !== false) {
                    return $sec;
                }
            }
        }
    }
    foreach (ai_chatbot_visitor_sections() as $sec) {
        if (strpos($q, strtolower($sec['slug'])) !== false || strpos($q, strtolower($sec['label'])) !== false) {
            return $sec;
        }
    }
    return null;
}

function ai_chatbot_visitor_search($query) {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'reply' => 'Type a topic, page name, or question about this site.'];
    }

    $lines = [];
    $section = ai_chatbot_visitor_match_section($query);
    if ($section) {
        $lines[] = 'Section: ' . $section['label'];
        $lines[] = '→ ' . $section['url'];
        $recent = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 4,
            'category_name' => $section['slug'] === 'home' ? '' : $section['slug'],
            's' => $section['slug'] === 'home' ? '' : '',
        ]);
        if ($recent->have_posts()) {
            $lines[] = '';
            $lines[] = 'Recent in this area:';
            while ($recent->have_posts()) {
                $recent->the_post();
                $lines[] = '• ' . get_the_title() . ' — ' . get_permalink();
            }
            wp_reset_postdata();
        }
    }

    $search = new WP_Query([
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        's' => $query,
        'posts_per_page' => 6,
    ]);

    if ($search->have_posts()) {
        if (!empty($lines)) $lines[] = '';
        $lines[] = 'Search results for “' . $query . '”:';
        while ($search->have_posts()) {
            $search->the_post();
            $lines[] = '• ' . get_the_title() . ' — ' . get_permalink();
        }
        wp_reset_postdata();
    } elseif (empty($lines)) {
        $lines[] = 'No exact matches. Try browsing:';
        foreach (array_slice(ai_chatbot_visitor_sections(), 0, 5) as $sec) {
            if ($sec['slug'] === 'home') continue;
            $lines[] = '• ' . $sec['label'] . ' — ' . $sec['url'];
        }
        $lines[] = '';
        $lines[] = 'Or use the site search: ' . home_url('/?s=' . rawurlencode($query));
    }

    return ['ok' => true, 'reply' => implode("\n", $lines)];
}

function ai_chatbot_visitor_ajax_handler() {
    check_ajax_referer('ai_chatbot_visitor_nonce', 'nonce');
    $message = sanitize_text_field($_POST['message'] ?? '');
    $result = ai_chatbot_visitor_search($message);
    if (!$result['ok']) {
        wp_send_json_error($result['reply']);
    }
    wp_send_json_success(['reply' => $result['reply']]);
}
add_action('wp_ajax_ai_chatbot_visitor_query', 'ai_chatbot_visitor_ajax_handler');
add_action('wp_ajax_nopriv_ai_chatbot_visitor_query', 'ai_chatbot_visitor_ajax_handler');

/* ── Admin: full AI assistant ─────────────────────────────────────────── */

function ai_chatbot_admin_ajax_handler() {
    check_ajax_referer('ai_chatbot_admin_nonce', 'nonce');
    if (!ai_chatbot_is_admin_user()) {
        wp_send_json_error('Admin access required.');
    }

    $message = sanitize_text_field($_POST['message'] ?? '');
    $history_json = stripslashes($_POST['history'] ?? '[]');
    $history = json_decode($history_json, true);
    if (!is_array($history)) $history = [];

    $system = get_option('ai_chatbot_system_prompt', 'You are Sourov\'s WordPress publishing assistant. Help draft content, SEO, scheduling, and site tasks.');
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
add_action('wp_ajax_ai_chatbot_admin_query', 'ai_chatbot_admin_ajax_handler');

function ai_chatbot_create_post() {
    check_ajax_referer('ai_chatbot_admin_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('edit_posts required');
    $title = sanitize_text_field($_POST['title'] ?? 'AI Draft');
    $content = wp_kses_post($_POST['content'] ?? '');
    $hours = intval($_POST['schedule_hours'] ?? 0);
    if (empty($content)) wp_send_json_error('No content');
    $post_data = [
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => $hours > 0 ? 'future' : 'draft',
        'post_type' => 'post',
        'post_author' => get_current_user_id() ?: 1,
    ];
    if ($hours > 0) {
        $post_data['post_date'] = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
        $post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime("+{$hours} hours"));
    }
    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) wp_send_json_error($post_id->get_error_message());
    wp_send_json_success(['post_id' => $post_id, 'edit_link' => admin_url('post.php?post=' . $post_id . '&action=edit')]);
}
add_action('wp_ajax_ai_chatbot_create_post', 'ai_chatbot_create_post');

/* ── UI rendering ─────────────────────────────────────────────────────── */

function ai_chatbot_render_widget($mode, $context = 'frontend') {
    $is_visitor = ($mode === 'visitor');
    $ajax_action = $is_visitor ? 'ai_chatbot_visitor_query' : 'ai_chatbot_admin_query';
    $nonce_action = $is_visitor ? 'ai_chatbot_visitor_nonce' : 'ai_chatbot_admin_nonce';
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce($nonce_action);
    $schedule = !$is_visitor && ai_chatbot_opt_on('ai_chatbot_schedule_enabled', 'no');
    $id = $is_visitor ? 'visitor' : 'admin';
    $toggle_label = $is_visitor ? 'Search this site' : 'AI Assistant';
    $toggle_icon = $is_visitor ? '&#x1F50D;' : '&#x1F4AC;';
    $header = $is_visitor ? 'Site Search &amp; Navigation' : 'AI Assistant (Admin)';
    $placeholder = $is_visitor ? 'e.g. english teaching, philosophy posts…' : 'Draft, schedule, ask anything…';
    $offset = ($is_visitor && ai_chatbot_is_admin_user() && ai_chatbot_opt_on('ai_chatbot_admin_frontend_enabled')) ? '90px' : '20px';
    $z = $is_visitor ? 99998 : 100001;
    ?>
    <style>
    #ai-chatbot-<?php echo esc_attr($id); ?>-container { font-family: system-ui, -apple-system, sans-serif; }
    .ai-chatbot-<?php echo esc_attr($id); ?>-floating { position: fixed; bottom: 20px; right: <?php echo esc_attr($offset); ?>; z-index: <?php echo (int) $z; ?>; box-shadow: 0 4px 20px rgba(0,0,0,0.25); max-width: 380px; width: 92%; border-radius: 12px; }
    .ai-chatbot-<?php echo esc_attr($id); ?>-toggle { position: fixed; bottom: 20px; right: <?php echo esc_attr($offset); ?>; z-index: <?php echo (int) ($z + 1); ?>; background: <?php echo $is_visitor ? '#2e7d32' : '#0073aa'; ?>; color: #fff; border: none; border-radius: 50%; width: 56px; height: 56px; font-size: 22px; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; }
    .ai-chatbot-<?php echo esc_attr($id); ?>-toggle:hover { filter: brightness(1.1); }
    </style>
    <script>window.aiChatbot_<?php echo esc_js($id); ?> = { ajax_url: '<?php echo esc_js($ajax_url); ?>', nonce: '<?php echo esc_js($nonce); ?>', action: '<?php echo esc_js($ajax_action); ?>', scheduleEnabled: <?php echo $schedule ? 'true' : 'false'; ?> };</script>
    <button id="ai-chatbot-<?php echo esc_attr($id); ?>-toggle" class="ai-chatbot-<?php echo esc_attr($id); ?>-toggle" aria-label="<?php echo esc_attr($toggle_label); ?>" title="<?php echo esc_attr($toggle_label); ?>"><?php echo $toggle_icon; ?></button>
    <div id="ai-chatbot-<?php echo esc_attr($id); ?>-container" class="ai-chatbot-<?php echo esc_attr($id); ?>-floating" style="border:1px solid #ccc;overflow:hidden;background:#fff;display:none;">
        <div style="background:<?php echo $is_visitor ? '#2e7d32' : 'linear-gradient(90deg,#0073aa,#005a87)'; ?>;color:#fff;padding:12px 16px;font-weight:600;display:flex;justify-content:space-between;align-items:center;">
            <span><?php echo $header; ?></span>
            <button type="button" class="ai-chatbot-<?php echo esc_attr($id); ?>-close" style="background:rgba(255,255,255,0.2);border:none;color:#fff;font-size:20px;width:28px;height:28px;border-radius:50%;cursor:pointer;">&times;</button>
        </div>
        <div id="ai-chatbot-<?php echo esc_attr($id); ?>-messages" style="height:300px;overflow-y:auto;padding:14px;background:#f8f9fa;font-size:14px;white-space:pre-wrap;"></div>
        <div style="display:flex;padding:12px;background:#fff;border-top:1px solid #eee;gap:8px;">
            <input type="text" id="ai-chatbot-<?php echo esc_attr($id); ?>-input" placeholder="<?php echo esc_attr($placeholder); ?>" style="flex:1;padding:10px 12px;border:1px solid #ccc;border-radius:8px;" />
            <button type="button" id="ai-chatbot-<?php echo esc_attr($id); ?>-send" style="padding:10px 16px;background:<?php echo $is_visitor ? '#2e7d32' : '#0073aa'; ?>;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;"><?php echo $is_visitor ? 'Find' : 'Send'; ?></button>
        </div>
    </div>
    <script>
    (function(){
        var cfg = window.aiChatbot_<?php echo esc_js($id); ?>;
        var toggleBtn = document.getElementById('ai-chatbot-<?php echo esc_js($id); ?>-toggle');
        var container = document.getElementById('ai-chatbot-<?php echo esc_js($id); ?>-container');
        var messagesDiv = document.getElementById('ai-chatbot-<?php echo esc_js($id); ?>-messages');
        var input = document.getElementById('ai-chatbot-<?php echo esc_js($id); ?>-input');
        var sendBtn = document.getElementById('ai-chatbot-<?php echo esc_js($id); ?>-send');
        var closeBtn = container ? container.querySelector('.ai-chatbot-<?php echo esc_js($id); ?>-close') : null;
        var history = [];
        function toggleChat(show){ if(!container)return; var s=show!==undefined?show:(container.style.display==='none'||!container.style.display); container.style.display=s?'block':'none'; if(s&&messagesDiv.children.length===0) addMsg(<?php echo $is_visitor ? "'Search posts, categories, or topics on this site.'" : "'Admin AI ready — draft, schedule, or ask.'"; ?>,'bot'); }
        if(toggleBtn) toggleBtn.onclick=function(){toggleChat();};
        if(closeBtn) closeBtn.onclick=function(){toggleChat(false);};
        function addMsg(t,who){ var m=document.createElement('div'); m.style.cssText='margin:6px 0;padding:10px 13px;border-radius:14px;max-width:92%;font-size:13px;line-height:1.45;white-space:pre-wrap;'; m.style.cssText+=who==='user'?'background:#333;color:#fff;margin-left:auto;':'background:#e9ecef;color:#212529;'; m.textContent=t; messagesDiv.appendChild(m); messagesDiv.scrollTop=messagesDiv.scrollHeight; }
        function send(){ var t=input.value.trim(); if(!t)return; addMsg(t,'user'); if(!<?php echo $is_visitor ? 'true' : 'false'; ?>) history.push({role:'user',content:t}); input.value='';
            fetch(cfg.ajax_url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:cfg.action,nonce:cfg.nonce,message:t,history:JSON.stringify(history)})})
            .then(function(r){return r.json();}).then(function(d){ if(d.success){ addMsg(d.data.reply,'bot'); if(!<?php echo $is_visitor ? 'true' : 'false'; ?>) history.push({role:'assistant',content:d.data.reply}); } else addMsg('Error: '+(d.data||'failed'),'bot'); })
            .catch(function(){ addMsg('Network error','bot'); }); }
        if(sendBtn) sendBtn.onclick=send;
        if(input) input.onkeypress=function(e){ if(e.key==='Enter') send(); };
    })();
    </script>
    <?php
}

function ai_chatbot_frontend_output() {
    if (is_admin()) return;

    $show_visitor = ai_chatbot_opt_on('ai_chatbot_visitor_enabled', 'yes');
    $show_admin_fe = ai_chatbot_opt_on('ai_chatbot_admin_frontend_enabled', 'yes') && ai_chatbot_is_admin_user();

    if ($show_visitor && !ai_chatbot_is_admin_user()) {
        ai_chatbot_render_widget('visitor', 'frontend');
    }
    if ($show_admin_fe) {
        ai_chatbot_render_widget('admin', 'frontend');
    }
}
add_action('wp_footer', 'ai_chatbot_frontend_output');

function ai_chatbot_wpadmin_output() {
    if (!ai_chatbot_opt_on('ai_chatbot_admin_wpadmin_enabled', 'yes') || !ai_chatbot_is_admin_user()) {
        return;
    }
    echo '<div id="ai-chatbot-wpadmin-wrap">';
    ai_chatbot_render_widget('admin', 'wpadmin');
    echo '</div>';
}
add_action('admin_footer', 'ai_chatbot_wpadmin_output');

function ai_chatbot_admin_bar_node($wp_admin_bar) {
    if (!ai_chatbot_opt_on('ai_chatbot_admin_wpadmin_enabled', 'yes') || !ai_chatbot_is_admin_user()) return;
    $wp_admin_bar->add_node([
        'id' => 'ai-chatbot-admin-launch',
        'title' => '&#x1F4AC; AI Assistant',
        'href' => '#',
        'meta' => ['onclick' => 'document.getElementById("ai-chatbot-admin-toggle").click();return false;'],
    ]);
}
add_action('admin_bar_menu', 'ai_chatbot_admin_bar_node', 100);

/* ── Admin settings page ──────────────────────────────────────────────── */

function ai_chatbot_run_diagnostics() {
    $cfg = ai_chatbot_resolve_config();
    $issues = [];
    $info = [
        'Visitor search widget: ' . (ai_chatbot_opt_on('ai_chatbot_visitor_enabled') ? 'ON (public)' : 'OFF'),
        'Admin widget (frontend): ' . (ai_chatbot_opt_on('ai_chatbot_admin_frontend_enabled') ? 'ON (logged-in editors)' : 'OFF'),
        'Admin widget (wp-admin): ' . (ai_chatbot_opt_on('ai_chatbot_admin_wpadmin_enabled') ? 'ON' : 'OFF'),
        'AI provider: ' . $cfg['provider'],
        'AI model: ' . $cfg['model'],
    ];
    if ($cfg['provider'] === 'shared_ollama') {
        $tunnel = $cfg['tunnel_url'];
        $info[] = 'Tunnel: ' . ($tunnel ?: '(empty)');
        if (preg_match('#^https?://127\.0\.0\.1#i', $tunnel) || preg_match('#^https?://localhost#i', $tunnel)) {
            $issues[] = 'Tunnel URL is localhost — Hostinger cannot reach your PC. Use a public Cloudflare Tunnel HTTPS URL.';
        }
    }
    return ['issues' => $issues, 'info' => $info];
}

function ai_chatbot_settings_page() {
    if (!current_user_can('manage_options')) return;
    $provider = get_option('ai_chatbot_provider', 'openrouter');
    $has_shared = ai_chatbot_has_shared_ollama();
    $diag = ai_chatbot_run_diagnostics();
    $test_result = isset($_GET['test_result']) ? sanitize_text_field(wp_unslash($_GET['test_result'])) : '';
    $test_ok = isset($_GET['test_ok']) ? $_GET['test_ok'] === '1' : null;
    ?>
    <div class="wrap">
        <h1>AI Chatbot Connector <small style="font-weight:normal;color:#666;">v<?php echo esc_html(AI_CHATBOT_VERSION); ?></small></h1>

        <div class="notice notice-info" style="padding:12px 16px;">
            <h2 style="margin:0 0 8px;font-size:15px;">Two separate widgets</h2>
            <table class="widefat" style="max-width:720px;background:#fff;">
                <thead><tr><th>Who</th><th>Widget</th><th>What it does</th></tr></thead>
                <tbody>
                    <tr><td><strong>Visitors</strong></td><td>Green &#x1F50D; button</td><td>Search posts &amp; navigate sections — no AI API, no login</td></tr>
                    <tr><td><strong>You (logged in)</strong></td><td>Blue &#x1F4AC; button</td><td>Full AI assistant — draft, schedule, Ollama/OpenRouter</td></tr>
                    <tr><td><strong>WP Admin</strong></td><td>Admin bar + blue button</td><td>Same AI assistant inside wp-admin</td></tr>
                </tbody>
            </table>
        </div>

        <?php if (!empty($diag['issues'])): ?>
        <div class="notice notice-warning"><ul style="margin:0 0 0 18px;"><?php foreach ($diag['issues'] as $i): ?><li><?php echo esc_html($i); ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($test_result !== ''): ?>
        <div class="notice notice-<?php echo $test_ok ? 'success' : 'error'; ?>"><p><?php echo esc_html($test_result); ?></p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('ai_chatbot_settings'); ?>
            <h2>Widget visibility</h2>
            <table class="form-table">
                <tr><th>Visitor search (public)</th><td><label><input type="checkbox" name="ai_chatbot_visitor_enabled" value="yes" <?php checked(ai_chatbot_opt_on('ai_chatbot_visitor_enabled')); ?> /> Green search button for all visitors</label></td></tr>
                <tr><th>Admin AI (frontend)</th><td><label><input type="checkbox" name="ai_chatbot_admin_frontend_enabled" value="yes" <?php checked(ai_chatbot_opt_on('ai_chatbot_admin_frontend_enabled')); ?> /> Blue AI button when you are logged in</label></td></tr>
                <tr><th>Admin AI (wp-admin)</th><td><label><input type="checkbox" name="ai_chatbot_admin_wpadmin_enabled" value="yes" <?php checked(ai_chatbot_opt_on('ai_chatbot_admin_wpadmin_enabled')); ?> /> AI panel inside WordPress admin + admin bar link</label></td></tr>
            </table>

            <h2>Admin AI provider (not used for visitor search)</h2>
            <table class="form-table">
                <tr><th>Provider</th><td>
                    <select name="ai_chatbot_provider" id="ai_chatbot_provider">
                        <option value="openrouter" <?php selected($provider, 'openrouter'); ?>>OpenRouter</option>
                        <option value="shared_ollama" <?php selected($provider, 'shared_ollama'); ?> <?php disabled(!$has_shared); ?>>Shared Ollama (AI Engine)</option>
                        <option value="xai" <?php selected($provider, 'xai'); ?>>xAI Grok</option>
                        <option value="custom" <?php selected($provider, 'custom'); ?>>Custom</option>
                    </select>
                    <?php if ($has_shared): ?><p class="description"><a href="<?php echo esc_url(admin_url('admin.php?page=aicu-ai-engine-settings')); ?>">AI Engine → Ollama Settings</a></p><?php endif; ?>
                </td></tr>
                <tr class="ai-chatbot-custom-fields"><th>Endpoint</th><td><input type="url" name="ai_chatbot_endpoint" value="<?php echo esc_attr(get_option('ai_chatbot_endpoint', 'https://openrouter.ai/api/v1')); ?>" class="regular-text" /></td></tr>
                <tr class="ai-chatbot-custom-fields"><th>API Key</th><td><input type="password" name="ai_chatbot_api_key" value="<?php echo esc_attr(get_option('ai_chatbot_api_key')); ?>" class="regular-text" autocomplete="new-password" /></td></tr>
                <tr class="ai-chatbot-custom-fields"><th>Model</th><td><input type="text" name="ai_chatbot_model" value="<?php echo esc_attr(get_option('ai_chatbot_model', 'meta-llama/llama-3.2-3b-instruct:free')); ?>" class="regular-text" /></td></tr>
                <tr><th>Ignore CF Access</th><td><label><input type="checkbox" name="ai_chatbot_ignore_cf_access" value="yes" <?php checked(ai_chatbot_opt_on('ai_chatbot_ignore_cf_access', 'no')); ?> /> Skip Cloudflare Access headers for Ollama</label></td></tr>
                <tr><th>System prompt</th><td><textarea name="ai_chatbot_system_prompt" rows="3" class="large-text"><?php echo esc_textarea(get_option('ai_chatbot_system_prompt', 'You are Sourov\'s WordPress publishing assistant.')); ?></textarea></td></tr>
                <tr><th>Post/Schedule buttons</th><td><label><input type="checkbox" name="ai_chatbot_schedule_enabled" value="yes" <?php checked(ai_chatbot_opt_on('ai_chatbot_schedule_enabled', 'no')); ?> /> Show draft/schedule after admin AI replies</label></td></tr>
            </table>
            <?php submit_button('Save Changes'); ?>
        </form>

        <hr><h2>Test admin AI connection</h2>
        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ai_chatbot_test_connection'), 'ai_chatbot_test')); ?>">Run test ping</a></p>
        <h3>Status</h3><ul><?php foreach ($diag['info'] as $line): ?><li><code><?php echo esc_html($line); ?></code></li><?php endforeach; ?></ul>
    </div>
    <script>(function(){var s=document.getElementById('ai_chatbot_provider'),r=document.querySelectorAll('.ai-chatbot-custom-fields');function t(){var show=s&&['custom','openrouter','xai'].indexOf(s.value)>=0;r.forEach(function(e){e.style.display=show?'':'none';});}if(s){s.onchange=t;t();}})();</script>
    <?php
}

add_action('admin_post_ai_chatbot_test_connection', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('ai_chatbot_test');
    $result = ai_chatbot_call_api([['role' => 'user', 'content' => 'Reply: pong']], 20);
    $msg = $result['ok'] ? ('OK: ' . substr($result['reply'], 0, 120)) : $result['error'];
    wp_safe_redirect(admin_url('options-general.php?page=ai-chatbot-connector&test_ok=' . ($result['ok'] ? '1' : '0') . '&test_result=' . rawurlencode($msg)));
    exit;
});

function ai_chatbot_activate() {
    if (!get_option('ai_chatbot_visitor_enabled')) update_option('ai_chatbot_visitor_enabled', 'yes');
    if (!get_option('ai_chatbot_admin_frontend_enabled')) update_option('ai_chatbot_admin_frontend_enabled', 'yes');
    if (!get_option('ai_chatbot_admin_wpadmin_enabled')) update_option('ai_chatbot_admin_wpadmin_enabled', 'yes');
    if (!get_option('ai_chatbot_provider')) update_option('ai_chatbot_provider', 'openrouter');
    delete_option('ai_chatbot_floating_widget');
}
register_activation_hook(__FILE__, 'ai_chatbot_activate');

add_action('plugins_loaded', function () {
    if (get_option('ai_chatbot_floating_widget') === 'yes' && get_option('ai_chatbot_visitor_enabled') === false) {
        update_option('ai_chatbot_visitor_enabled', 'yes');
        delete_option('ai_chatbot_floating_widget');
    }
});
