<?php
/**
 * Plugin Name: AI Chatbot Connector (Ollama / Grok / OpenRouter)
 * Plugin URI: https://github.com/sourovdeb/wp-ai-chatbot-connector
 * Description: v0.2 - Multi-turn chat, floating toggle widget, save/schedule posts from chat. OpenAI-compatible for local Ollama or remote (Grok/OpenRouter). Ties to automated publishing pipeline. Shortcode + auto-floating.
 * Version: 0.2.0
 * Author: Sourov Deb (via Grok)
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

// Register settings
function ai_chatbot_register_settings() {
    register_setting('ai_chatbot_settings', 'ai_chatbot_endpoint');
    register_setting('ai_chatbot_settings', 'ai_chatbot_api_key');
    register_setting('ai_chatbot_settings', 'ai_chatbot_model');
    register_setting('ai_chatbot_settings', 'ai_chatbot_system_prompt');
    register_setting('ai_chatbot_settings', 'ai_chatbot_floating_widget');
    register_setting('ai_chatbot_settings', 'ai_chatbot_schedule_enabled');
}
add_action('admin_init', 'ai_chatbot_register_settings');

// Admin menu
function ai_chatbot_admin_menu() {
    add_options_page('AI Chatbot Connector', 'AI Chatbot', 'manage_options', 'ai-chatbot-connector', 'ai_chatbot_settings_page');
}
add_action('admin_menu', 'ai_chatbot_admin_menu');

function ai_chatbot_settings_page() {
    ?>
    <div class="wrap">
        <h1>AI Chatbot Connector Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('ai_chatbot_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>API Endpoint (OpenAI-compatible)</th>
                    <td>
                        <input type="text" name="ai_chatbot_endpoint" value="<?php echo esc_attr(get_option('ai_chatbot_endpoint', 'https://openrouter.ai/api/v1')); ?>" class="regular-text" />
                        <p class="description">Examples: http://YOUR-PUBLIC-OLLAMA-IP:11434/v1 | https://openrouter.ai/api/v1 | https://api.x.ai/v1</p>
                    </td>
                </tr>
                <tr>
                    <th>API Key</th>
                    <td>
                        <input type="password" name="ai_chatbot_api_key" value="<?php echo esc_attr(get_option('ai_chatbot_api_key')); ?>" class="regular-text" />
                        <p class="description">Stored securely in WP. Often empty for local Ollama.</p>
                    </td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td>
                        <input type="text" name="ai_chatbot_model" value="<?php echo esc_attr(get_option('ai_chatbot_model', 'meta-llama/llama-3.2-3b-instruct:free')); ?>" class="regular-text" />
                        <p class="description">llama3.2 (Ollama) or OpenRouter/Grok slug</p>
                    </td>
                </tr>
                <tr>
                    <th>System Prompt</th>
                    <td>
                        <textarea name="ai_chatbot_system_prompt" rows="4" class="large-text"><?php echo esc_textarea(get_option('ai_chatbot_system_prompt', 'You are a helpful AI assistant for this WordPress site and publishing pipeline.')); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th>Floating Widget (site-wide)</th>
                    <td>
                        <input type="checkbox" name="ai_chatbot_floating_widget" value="yes" <?php checked(get_option('ai_chatbot_floating_widget', 'yes'), 'yes'); ?> />
                        <span class="description">Always show 💬 toggle button + chat (bottom-right).</span>
                    </td>
                </tr>
                <tr>
                    <th>Enable Post/Schedule from Chat</th>
                    <td>
                        <input type="checkbox" name="ai_chatbot_schedule_enabled" value="yes" <?php checked(get_option('ai_chatbot_schedule_enabled', 'no'), 'yes'); ?> />
                        <span class="description">Show draft/schedule buttons after replies. Creates posts (ties to your fixer/publishing scripts).</span>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <p><strong>Usage:</strong> Floating widget auto if enabled. Or shortcode <code>[ai_chatbot]</code>. Multi-turn enabled. For local Ollama: expose securely.</p>
        <p><strong>Security:</strong> Key server-only. Nonce + caps. Post creation needs edit_posts.</p>
    </div>
    <?php
}

// Shortcode with multi-turn, floating, schedule buttons
function ai_chatbot_shortcode($atts) {
    $atts = shortcode_atts(array('embedded' => 'false'), $atts);
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('ai_chatbot_nonce');
    $schedule_enabled = get_option('ai_chatbot_schedule_enabled', 'no') === 'yes';
    ob_start();
    ?>
    <style>
        #ai-chatbot-container { font-family: system-ui, -apple-system, sans-serif; }
        .ai-chatbot-floating { position: fixed; bottom: 20px; right: 20px; z-index: 99999; box-shadow: 0 4px 20px rgba(0,0,0,0.25); max-width: 380px; width: 92%; border-radius: 12px; }
        .ai-chatbot-toggle { position: fixed; bottom: 20px; right: 20px; z-index: 100000; background: #0073aa; color: #fff; border: none; border-radius: 50%; width: 56px; height: 56px; font-size: 26px; cursor: pointer; box-shadow: 0 4px 12px rgba(0,115,170,0.4); display: flex; align-items: center; justify-content: center; transition: transform 0.2s; }
        .ai-chatbot-toggle:hover { transform: scale(1.05); background: #005a87; }
        .ai-chatbot-hidden { display: none !important; }
    </style>
    <script>
        var aiChatbot = {
            ajax_url: '<?php echo esc_js($ajax_url); ?>',
            nonce: '<?php echo esc_js($nonce); ?>',
            scheduleEnabled: <?php echo $schedule_enabled ? 'true' : 'false'; ?>
        };
    </script>

    <button id="ai-chatbot-toggle" class="ai-chatbot-toggle" aria-label="Open AI Chat">💬</button>

    <div id="ai-chatbot-container" class="ai-chatbot-floating" style="border: 1px solid #ccc; overflow: hidden; background: #fff; display: none;">
        <div id="ai-chatbot-header" style="background: linear-gradient(90deg, #0073aa, #005a87); color: #fff; padding: 12px 16px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; font-size: 15px;">
            <span>AI Assistant • Multi-turn</span>
            <button id="ai-chatbot-close" style="background: rgba(255,255,255,0.2); border: none; color: #fff; font-size: 20px; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; line-height: 1;">×</button>
        </div>
        <div id="ai-chatbot-messages" style="height: 320px; overflow-y: auto; padding: 14px; background: #f8f9fa; font-size: 14px;"></div>
        <div id="ai-chatbot-input-area" style="display: flex; padding: 12px; background: #fff; border-top: 1px solid #eee; gap: 8px;">
            <input type="text" id="ai-chatbot-input" placeholder="Message or 'post: Title | idea...'" style="flex:1; padding: 10px 12px; border: 1px solid #ccc; border-radius: 8px; font-size: 14px;" />
            <button id="ai-chatbot-send" style="padding: 10px 20px; background: #0073aa; color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Send</button>
        </div>
        <?php if ($schedule_enabled): ?>
        <div id="ai-chatbot-schedule-bar" style="padding: 6px 14px; background: #f1f3f5; font-size: 11px; color: #555; border-top: 1px solid #eee;">Post/Schedule buttons after replies</div>
        <?php endif; ?>
    </div>

    <script>
        (function() {
            const toggleBtn = document.getElementById('ai-chatbot-toggle');
            const container = document.getElementById('ai-chatbot-container');
            const messagesDiv = document.getElementById('ai-chatbot-messages');
            const input = document.getElementById('ai-chatbot-input');
            const sendBtn = document.getElementById('ai-chatbot-send');
            const closeBtn = document.getElementById('ai-chatbot-close');

            let conversationHistory = [];

            function toggleChat(forceShow) {
                const isHidden = container.style.display === 'none' || container.style.display === '';
                const show = (forceShow !== undefined) ? forceShow : isHidden;
                container.style.display = show ? 'block' : 'none';
                if (show && messagesDiv.children.length === 0) {
                    addMessage('Hello! Multi-turn + post tools ready. Toggle always available.', false);
                }
            }

            toggleBtn.onclick = () => toggleChat();
            if (closeBtn) closeBtn.onclick = () => toggleChat(false);

            function addMessage(text, isUser, isSystem) {
                const msg = document.createElement('div');
                msg.style.cssText = 'margin:6px 0; padding:10px 13px; border-radius:14px; max-width:82%; font-size:14px; line-height:1.45; white-space:pre-wrap;';
                if (isUser) {
                    msg.style.cssText += 'background:#0073aa; color:#fff; margin-left:auto; border-bottom-right-radius:4px;';
                } else if (isSystem) {
                    msg.style.cssText += 'background:#e3f2fd; color:#1565c0; font-style:italic;';
                } else {
                    msg.style.cssText += 'background:#e9ecef; color:#212529; border-bottom-left-radius:4px;';
                }
                msg.textContent = text;
                messagesDiv.appendChild(msg);
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }

            function addPostButtons(replyText) {
                if (!aiChatbot.scheduleEnabled) return;
                const bar = document.createElement('div');
                bar.style.cssText = 'margin:8px 0 4px; display:flex; gap:6px; flex-wrap:wrap;';
                
                const draftBtn = document.createElement('button');
                draftBtn.textContent = '📝 Save as Draft';
                draftBtn.style.cssText = 'font-size:11px; padding:5px 10px; background:#198754; color:#fff; border:none; border-radius:5px; cursor:pointer;';
                draftBtn.onclick = () => createDraftPost(replyText, 0);

                const schedBtn = document.createElement('button');
                schedBtn.textContent = '⏰ Schedule +24h';
                schedBtn.style.cssText = 'font-size:11px; padding:5px 10px; background:#fd7e14; color:#fff; border:none; border-radius:5px; cursor:pointer;';
                schedBtn.onclick = () => createDraftPost(replyText, 24);

                bar.appendChild(draftBtn);
                bar.appendChild(schedBtn);
                messagesDiv.appendChild(bar);
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }

            function createDraftPost(content, hours) {
                const title = (content.split('\n')[0] || content).substring(0, 70).trim() + (content.length > 70 ? '...' : '');
                fetch(aiChatbot.ajax_url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'ai_chatbot_create_post',
                        nonce: aiChatbot.nonce,
                        title: title,
                        content: content,
                        schedule_hours: hours || 0
                    })
                }).then(r => r.json()).then(data => {
                    if (data.success && data.data.edit_link) {
                        addMessage('✅ Post created. Edit: ' + data.data.edit_link, false, true);
                    } else {
                        addMessage('Post error: ' + (data.data || 'Check caps'), false);
                    }
                }).catch(() => addMessage('Error creating post', false));
            }

            sendBtn.onclick = () => {
                const text = input.value.trim();
                if (!text) return;
                addMessage(text, true);
                conversationHistory.push({role: 'user', content: text});
                input.value = '';

                fetch(aiChatbot.ajax_url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'ai_chatbot_query',
                        nonce: aiChatbot.nonce,
                        message: text,
                        history: JSON.stringify(conversationHistory)
                    })
                }).then(r => r.json()).then(data => {
                    if (data.success) {
                        const reply = data.data.reply || 'No reply';
                        addMessage(reply, false);
                        conversationHistory.push({role: 'assistant', content: reply});
                        addPostButtons(reply);
                    } else {
                        addMessage('Error: ' + (data.data || 'Try again'), false);
                    }
                }).catch(() => addMessage('Connection issue', false));
            };

            input.onkeypress = e => { if (e.key === 'Enter') sendBtn.onclick(); };

            setTimeout(() => {
                if (messagesDiv.children.length === 0) {
                    addMessage('Ready. Multi-turn + post/schedule active.', false);
                }
            }, 400);
        })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('ai_chatbot', 'ai_chatbot_shortcode');

// Query handler with multi-turn history
function ai_chatbot_ajax_handler() {
    check_ajax_referer('ai_chatbot_nonce', 'nonce');
    if (!current_user_can('read')) wp_send_json_error('Permission denied');

    $message = sanitize_text_field($_POST['message'] ?? '');
    $history_json = stripslashes($_POST['history'] ?? '[]');
    $history = json_decode($history_json, true);
    if (!is_array($history)) $history = [];

    if (empty($message) && empty($history)) wp_send_json_error('No message');

    $endpoint = get_option('ai_chatbot_endpoint', 'https://openrouter.ai/api/v1');
    $api_key = get_option('ai_chatbot_api_key', '');
    $model = get_option('ai_chatbot_model', 'meta-llama/llama-3.2-3b-instruct:free');
    $system = get_option('ai_chatbot_system_prompt', 'You are a helpful AI assistant for this WordPress site and publishing pipeline.');

    $url = rtrim($endpoint, '/') . '/chat/completions';

    $messages = [ ['role' => 'system', 'content' => $system] ];
    foreach ($history as $turn) {
        if (isset($turn['role'], $turn['content'])) {
            $messages[] = ['role' => $turn['role'], 'content' => sanitize_text_field($turn['content'])];
        }
    }
    if (empty($history) || end($history)['role'] !== 'user') {
        $messages[] = ['role' => 'user', 'content' => $message];
    }

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 600,
        'temperature' => 0.7
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    if (empty($api_key)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        wp_send_json_error('API error: ' . $response);
    }

    $data = json_decode($response, true);
    $reply = $data['choices'][0]['message']['content'] ?? 'No response from AI.';

    wp_send_json_success(['reply' => $reply]);
}
add_action('wp_ajax_ai_chatbot_query', 'ai_chatbot_ajax_handler');
add_action('wp_ajax_nopriv_ai_chatbot_query', 'ai_chatbot_ajax_handler');

// Create post/schedule handler
function ai_chatbot_create_post() {
    check_ajax_referer('ai_chatbot_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('edit_posts permission required');

    $title = sanitize_text_field($_POST['title'] ?? 'AI Draft');
    $content = wp_kses_post($_POST['content'] ?? '');
    $hours = intval($_POST['schedule_hours'] ?? 0);

    if (empty($content)) wp_send_json_error('No content');

    $post_data = [
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => ($hours > 0) ? 'future' : 'draft',
        'post_type'    => 'post',
        'post_author'  => get_current_user_id() ?: 1,
    ];
    if ($hours > 0) {
        $post_data['post_date'] = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
        $post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime("+{$hours} hours"));
    }

    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) wp_send_json_error($post_id->get_error_message());

    $edit_link = admin_url('post.php?post=' . $post_id . '&action=edit');
    wp_send_json_success(['post_id' => $post_id, 'edit_link' => $edit_link, 'status' => ($hours > 0 ? 'scheduled' : 'draft')]);
}
add_action('wp_ajax_ai_chatbot_create_post', 'ai_chatbot_create_post');

// Floating in footer if enabled
function ai_chatbot_floating_output() {
    if (get_option('ai_chatbot_floating_widget', 'yes') === 'yes') {
        echo do_shortcode('[ai_chatbot]');
    }
}
add_action('wp_footer', 'ai_chatbot_floating_output');

// Activation defaults
function ai_chatbot_activate() {
    if (!get_option('ai_chatbot_endpoint')) update_option('ai_chatbot_endpoint', 'https://openrouter.ai/api/v1');
    if (!get_option('ai_chatbot_model')) update_option('ai_chatbot_model', 'meta-llama/llama-3.2-3b-instruct:free');
    if (!get_option('ai_chatbot_system_prompt')) update_option('ai_chatbot_system_prompt', 'You are a helpful AI assistant for this WordPress site and publishing pipeline.');
    if (!get_option('ai_chatbot_floating_widget')) update_option('ai_chatbot_floating_widget', 'yes');
    if (!get_option('ai_chatbot_schedule_enabled')) update_option('ai_chatbot_schedule_enabled', 'no');
}
register_activation_hook(__FILE__, 'ai_chatbot_activate');