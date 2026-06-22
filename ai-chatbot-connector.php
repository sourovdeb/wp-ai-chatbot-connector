<?php
/**
 * Plugin Name: AI Chatbot Connector (Ollama / Grok / OpenRouter)
 * Plugin URI: https://github.com/sourovdeb/wp-ai-chatbot-connector
 * Description: Simple frontend chatbot for WordPress. Connects to local Ollama (via exposed endpoint) or remote APIs like xAI Grok / OpenRouter via OpenAI-compatible interface. Settings in WP admin. Shortcode: [ai_chatbot]
 * Version: 0.1.0
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
                        <p class="description">Examples: http://YOUR-PUBLIC-OLLAMA-IP:11434/v1 (expose Ollama securely) | https://openrouter.ai/api/v1 | https://api.x.ai/v1 (for Grok if compatible)</p>
                    </td>
                </tr>
                <tr>
                    <th>API Key</th>
                    <td>
                        <input type="password" name="ai_chatbot_api_key" value="<?php echo esc_attr(get_option('ai_chatbot_api_key')); ?>" class="regular-text" />
                        <p class="description">Your key. Stored in WP options. For local Ollama often empty or 'ollama'.</p>
                    </td>
                </tr>
                <tr>
                    <th>Model</th>
                    <td>
                        <input type="text" name="ai_chatbot_model" value="<?php echo esc_attr(get_option('ai_chatbot_model', 'meta-llama/llama-3.2-3b-instruct:free')); ?>" class="regular-text" />
                        <p class="description">e.g. llama3.2 for Ollama, or OpenRouter model slug like meta-llama/llama-3.2-3b-instruct:free, or grok-beta / appropriate for xAI.</p>
                    </td>
                </tr>
                <tr>
                    <th>System Prompt</th>
                    <td>
                        <textarea name="ai_chatbot_system_prompt" rows="4" class="large-text"><?php echo esc_textarea(get_option('ai_chatbot_system_prompt', 'You are a helpful assistant for this WordPress site.')); ?></textarea>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <p><strong>Usage:</strong> Add shortcode <code>[ai_chatbot]</code> to any page/post. Or use as floating widget (extend JS). For local Ollama: Run Ollama with --host 0.0.0.0 and expose port securely (e.g. ngrok, Cloudflare Tunnel, or VPS). Never expose without auth/firewall.</p>
        <p><strong>Security:</strong> API key hidden from frontend. Nonce + capability checks in AJAX.</p>
    </div>
    <?php
}

// No external JS needed; chat UI and logic inline in shortcode for simplicity and self-containment.

// Shortcode
function ai_chatbot_shortcode($atts) {
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('ai_chatbot_nonce');
    ob_start();
    ?>
    <script>
        var aiChatbot = {
            ajax_url: '<?php echo esc_js($ajax_url); ?>',
            nonce: '<?php echo esc_js($nonce); ?>'
        };
    </script>
    <div id="ai-chatbot-container" style="max-width: 600px; margin: 20px auto; border: 1px solid #ccc; border-radius: 8px; overflow: hidden; font-family: sans-serif;">
        <div id="ai-chatbot-header" style="background: #0073aa; color: white; padding: 10px; text-align: center; font-weight: bold;">AI Assistant</div>
        <div id="ai-chatbot-messages" style="height: 300px; overflow-y: auto; padding: 10px; background: #f9f9f9;"></div>
        <div id="ai-chatbot-input-area" style="display: flex; padding: 10px; background: white; border-top: 1px solid #ccc;">
            <input type="text" id="ai-chatbot-input" placeholder="Type your message..." style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" />
            <button id="ai-chatbot-send" style="margin-left: 8px; padding: 8px 16px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;">Send</button>
        </div>
    </div>
    <script>
        // Basic JS for chat
        (function() {
            const container = document.getElementById('ai-chatbot-container');
            const messagesDiv = document.getElementById('ai-chatbot-messages');
            const input = document.getElementById('ai-chatbot-input');
            const sendBtn = document.getElementById('ai-chatbot-send');

            function addMessage(text, isUser) {
                const msg = document.createElement('div');
                msg.style.margin = '8px 0';
                msg.style.padding = '8px 12px';
                msg.style.borderRadius = '12px';
                msg.style.maxWidth = '80%';
                if (isUser) {
                    msg.style.background = '#0073aa';
                    msg.style.color = 'white';
                    msg.style.marginLeft = 'auto';
                } else {
                    msg.style.background = '#e0e0e0';
                    msg.style.color = '#333';
                }
                msg.textContent = text;
                messagesDiv.appendChild(msg);
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }

            sendBtn.onclick = function() {
                const text = input.value.trim();
                if (!text) return;
                addMessage(text, true);
                input.value = '';

                // Call AJAX
                fetch(aiChatbot.ajax_url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'ai_chatbot_query',
                        nonce: aiChatbot.nonce,
                        message: text
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        addMessage(data.data.reply, false);
                    } else {
                        addMessage('Error: ' + (data.data || 'Unknown'), false);
                    }
                })
                .catch(e => addMessage('Network error', false));
            };

            input.onkeypress = function(e) {
                if (e.key === 'Enter') sendBtn.onclick();
            };

            // Welcome
            setTimeout(() => addMessage('Hello! How can I help with the site content or questions?', false), 500);
        })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('ai_chatbot', 'ai_chatbot_shortcode');

// AJAX handler
function ai_chatbot_ajax_handler() {
    check_ajax_referer('ai_chatbot_nonce', 'nonce');
    if (!current_user_can('read')) wp_send_json_error('Permission denied');

    $message = sanitize_text_field($_POST['message'] ?? '');
    if (empty($message)) wp_send_json_error('No message');

    $endpoint = get_option('ai_chatbot_endpoint', 'https://openrouter.ai/api/v1');
    $api_key = get_option('ai_chatbot_api_key', '');
    $model = get_option('ai_chatbot_model', 'meta-llama/llama-3.2-3b-instruct:free');
    $system = get_option('ai_chatbot_system_prompt', 'You are a helpful assistant.');

    $url = rtrim($endpoint, '/') . '/chat/completions';

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $message]
        ],
        'max_tokens' => 500,
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); // for some local
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        wp_send_json_error('API error: ' + $response);
    }

    $data = json_decode($response, true);
    $reply = $data['choices'][0]['message']['content'] ?? 'No response from AI.';

    wp_send_json_success(['reply' => $reply]);
}
add_action('wp_ajax_ai_chatbot_query', 'ai_chatbot_ajax_handler');
add_action('wp_ajax_nopriv_ai_chatbot_query', 'ai_chatbot_ajax_handler');

// Activation: Set defaults
function ai_chatbot_activate() {
    if (!get_option('ai_chatbot_endpoint')) update_option('ai_chatbot_endpoint', 'https://openrouter.ai/api/v1');
    if (!get_option('ai_chatbot_model')) update_option('ai_chatbot_model', 'meta-llama/llama-3.2-3b-instruct:free');
    if (!get_option('ai_chatbot_system_prompt')) update_option('ai_chatbot_system_prompt', 'You are a helpful AI assistant integrated into this WordPress site. Answer questions about content, provide summaries, or assist visitors.');
}
register_activation_hook(__FILE__, 'ai_chatbot_activate');