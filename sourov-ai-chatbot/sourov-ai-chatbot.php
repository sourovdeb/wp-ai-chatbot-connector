<?php
/**
 * Plugin Name: Sourov AI Chatbot (Visitor + Admin)
 * Description: AI Chatbot for visitors (smart site search & navigation) + full admin features. Works on frontend and wp-admin.
 * Version: 1.0
 * Author: Sourov
 */

if (!defined('ABSPATH')) exit;

function sourov_ai_chatbot_settings() {
    add_options_page(
        'Sourov AI Chatbot Settings',
        'AI Chatbot',
        'manage_options',
        'sourov-ai-chatbot',
        'sourov_ai_chatbot_settings_page'
    );
}
add_action('admin_menu', 'sourov_ai_chatbot_settings');

function sourov_ai_chatbot_settings_page() {
    if (isset($_POST['sourov_ai_key'])) {
        update_option('sourov_ai_openrouter_key', sanitize_text_field($_POST['sourov_ai_key']));
        echo '<div class="updated"><p>API Key saved.</p></div>';
    }
    $key = get_option('sourov_ai_openrouter_key', '');
    ?>
    <div class="wrap">
        <h1>Sourov AI Chatbot Settings</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th>OpenRouter API Key</th>
                    <td>
                        <input type="password" name="sourov_ai_key" value="<?php echo esc_attr($key); ?>" class="regular-text" style="width:400px;">
                        <p class="description">Get free key at <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai</a>. Use model <code>openrouter/free</code> or any free model.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Key'); ?>
        </form>
        <p><strong>Shortcode for visitors:</strong> <code>[sourov_ai_chatbot]</code></p>
        <p>Floating chat appears automatically on frontend for visitors.</p>
    </div>
    <?php
}

function sourov_ai_chatbot_shortcode() {
    ob_start();
    ?>
    <div id="sourov-ai-chat" style="max-width:600px; margin:20px auto; border:1px solid #ddd; border-radius:12px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
        <div style="background:#1e293b; color:white; padding:12px 16px; font-weight:600;">
             Sourov AI Assistant <span style="font-size:12px; opacity:0.8;">— Site Search & Navigation</span>
        </div>
        <div id="sourov-chat-messages" style="height:320px; overflow-y:auto; padding:16px; background:#f8fafc; font-size:14px; line-height:1.5;"></div>
        <div style="display:flex; border-top:1px solid #ddd; background:white;">
            <input type="text" id="sourov-chat-input" placeholder="Ask about the site... (e.g. 'How to start CELTA?')"
                   style="flex:1; padding:12px; border:none; font-size:14px;" onkeypress="if(event.key==='Enter') sourovSendMessage()">
            <button onclick="sourovSendMessage()" style="padding:12px 20px; background:#3b82f6; color:white; border:none; cursor:pointer;">Send</button>
        </div>
    </div>

    <script>
    async function sourovSendMessage() {
        const input = document.getElementById('sourov-chat-input');
        const messagesDiv = document.getElementById('sourov-chat-messages');
        const userMsg = input.value.trim();
        if (!userMsg) return;

        messagesDiv.innerHTML += `<div style="margin:8px 0; text-align:right;"><span style="background:#3b82f6; color:white; padding:8px 12px; border-radius:18px; display:inline-block; max-width:80%;">${userMsg}</span></div>`;
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
        input.value = '';

        const thinking = document.createElement('div');
        thinking.innerHTML = `<div style="margin:8px 0; color:#64748b;">Thinking...</div>`;
        messagesDiv.appendChild(thinking);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;

        try {
            const response = await fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'sourov_ai_chat',
                    message: userMsg,
                    is_admin: <?php echo is_user_logged_in() && current_user_can('manage_options') ? 'true' : 'false'; ?>
                })
            });
            const data = await response.json();
            thinking.remove();
            messagesDiv.innerHTML += `<div style="margin:8px 0;"><span style="background:#e2e8f0; padding:8px 12px; border-radius:18px; display:inline-block; max-width:80%;">${data.reply}</span></div>`;
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        } catch(e) {
            thinking.remove();
            messagesDiv.innerHTML += `<div style="color:red;">Error. Please try again.</div>`;
        }
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('sourov_ai_chatbot', 'sourov_ai_chatbot_shortcode');

function sourov_ai_floating_chat() {
    if (is_admin()) return;
    echo do_shortcode('[sourov_ai_chatbot]');
}
add_action('wp_footer', 'sourov_ai_floating_chat');

function sourov_ai_chat_ajax() {
    $message = sanitize_text_field($_POST['message'] ?? '');
    $is_admin = !empty($_POST['is_admin']) && current_user_can('manage_options');

    if (!$message) wp_send_json(['reply' => 'Please type a message.']);

    $api_key = get_option('sourov_ai_openrouter_key');
    if (!$api_key) {
        wp_send_json(['reply' => 'AI is not configured yet. Please add OpenRouter key in Settings → AI Chatbot.']);
    }

    $context = "You are a helpful AI assistant for the website sourovdeb.com.\n";
    if ($is_admin) {
        $context .= "You are in ADMIN mode. You can help with content creation, listing posts, drafting, and site management.\n";
    } else {
        $context .= "You are in VISITOR mode. Only answer using information from this website. Help users find content and navigate. Be friendly and concise.\n";
    }

    $search_results = '';
    if (!$is_admin) {
        $query = new WP_Query([
            's' => $message,
            'posts_per_page' => 5,
            'post_status' => 'publish'
        ]);
        if ($query->have_posts()) {
            $search_results = "Relevant pages found:\n";
            while ($query->have_posts()) {
                $query->the_post();
                $search_results .= "- " . get_the_title() . " (" . get_permalink() . ")\n";
            }
            wp_reset_postdata();
        }
    }

    $full_prompt = $context . "\n" . $search_results . "\nUser question: " . $message;

    $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => home_url(),
        ],
        'body' => json_encode([
            'model' => 'openrouter/free',
            'messages' => [
                ['role' => 'system', 'content' => $full_prompt],
                ['role' => 'user', 'content' => $message]
            ],
            'max_tokens' => 600,
            'temperature' => 0.7
        ]),
        'timeout' => 25
    ]);

    if (is_wp_error($response)) {
        wp_send_json(['reply' => 'Sorry, AI is temporarily unavailable.']);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $reply = $body['choices'][0]['message']['content'] ?? 'Sorry, I could not generate a response.';

    wp_send_json(['reply' => $reply]);
}
add_action('wp_ajax_sourov_ai_chat', 'sourov_ai_chat_ajax');
add_action('wp_ajax_nopriv_sourov_ai_chat', 'sourov_ai_chat_ajax');

function sourov_ai_admin_notice() {
    if (get_current_screen()->base === 'settings_page_sourov-ai-chatbot') return;
    if (current_user_can('manage_options')) {
        echo '<div class="notice notice-info"><p><strong>Sourov AI Chatbot</strong> is active. Add OpenRouter key in <a href="' . admin_url('options-general.php?page=sourov-ai-chatbot') . '">Settings → AI Chatbot</a>. Use shortcode <code>[sourov_ai_chatbot]</code> anywhere.</p></div>';
    }
}
add_action('admin_notices', 'sourov_ai_admin_notice');
