<?php
/**
 * Plugin Name: Sourov AI Controller
 * Description: Remote AI control — auto-schedule, generate & post content via REST API. No third-party dependency.
 * Version: 1.2
 * Author: Sourov Deb
 * Conflict-Safe: Yes
 * Cache-Aware: Yes
 * Hooks-Order: priority 20
 */

if (!defined('ABSPATH')) exit;

if (!defined('SOUROV_AI_PRIORITY')) {
    define('SOUROV_AI_PRIORITY', 20);
}

add_action('rest_api_init', function () {
    register_rest_route('sourov/v1', '/ai-post', [
        'methods' => 'POST',
        'callback' => 'sourov_ai_create_post',
        'permission_callback' => 'sourov_ai_auth',
    ]);

    register_rest_route('sourov/v1', '/scheduled', [
        'methods' => 'GET',
        'callback' => 'sourov_ai_get_scheduled',
        'permission_callback' => 'sourov_ai_auth',
    ]);

    register_rest_route('sourov/v1', '/drafts', [
        'methods' => 'GET',
        'callback' => 'sourov_ai_get_drafts',
        'permission_callback' => 'sourov_ai_auth',
    ]);

    register_rest_route('sourov/v1', '/schedule-drafts', [
        'methods' => 'POST',
        'callback' => 'sourov_ai_schedule_drafts',
        'permission_callback' => 'sourov_ai_auth',
    ]);

    register_rest_route('sourov/v1', '/post/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'sourov_ai_delete_post',
        'permission_callback' => 'sourov_ai_auth',
    ]);

    register_rest_route('sourov/v1', '/bulk', [
        'methods' => 'POST',
        'callback' => 'sourov_ai_bulk_post',
        'permission_callback' => 'sourov_ai_auth',
    ]);

    register_rest_route('sourov/v1', '/status', [
        'methods' => 'GET',
        'callback' => 'sourov_ai_status',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('sourov/v1', '/health', [
        'methods' => 'GET',
        'callback' => 'sourov_ai_health_check',
        'permission_callback' => '__return_true',
    ]);
}, 10);

function sourov_ai_auth($request) {
    if (is_user_logged_in()) return true;

    $secret = get_option('sourov_ai_secret_key', '');
    $sent = $request->get_header('X-Sourov-Key');

    if ($secret && $sent === $secret) {
        return true;
    }

    return new WP_Error('unauthorized', 'Unauthorized', ['status' => 403]);
}

function sourov_ai_resolve_categories($params) {
    $categories = [];
    if (isset($params['categories'])) {
        $categories = array_map('absint', (array) $params['categories']);
    }
    if (empty($categories) && !empty($params['category'])) {
        $name = sanitize_text_field($params['category']);
        $term = get_term_by('name', $name, 'category');
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($name), 'category');
        }
        if ($term && !is_wp_error($term)) {
            $categories = [(int) $term->term_id];
        }
    }
    return array_values(array_filter($categories));
}

function sourov_ai_resolve_tags($params) {
    if (!isset($params['tags'])) return [];
    $tags = (array) $params['tags'];
    if (count($tags) === 1 && is_string($tags[0]) && strpos($tags[0], ',') !== false) {
        $tags = array_map('trim', explode(',', $tags[0]));
    }
    return array_values(array_filter(array_map('sanitize_text_field', $tags)));
}

function sourov_ai_infer_category_ids($title, $content) {
    $text = strtolower($title . ' ' . wp_strip_all_tags($content));
    if (preg_match('/english teaching|elt|celta|tesol|grammar|classroom|lesson|learner/', $text)) {
        return [9];
    }
    if (preg_match('/mental health|anxiety|depress|wellbeing|therapy|stress|ptsd|bipolar|adhd/', $text)) {
        return [582];
    }
    if (preg_match('/philosophy|stoic|ethic|existential|sartre|bad faith/', $text)) {
        return [581];
    }
    if (preg_match('/career|professional|job|cv|resume|interview|reunion/', $text)) {
        return [56];
    }
    return [];
}

function sourov_ai_create_post($request) {
    $params = $request->get_json_params();
    $title = sanitize_text_field($params['title'] ?? '');
    $content = wp_kses_post($params['content'] ?? '');
    $status = in_array($params['status'] ?? '', ['draft', 'pending', 'publish'], true) ? $params['status'] : 'draft';
    $schedule = sanitize_text_field($params['schedule'] ?? ($params['date'] ?? ''));
    $meta_desc = sanitize_text_field($params['meta_desc'] ?? ($params['meta_description'] ?? ''));
    $seo_title = sanitize_text_field($params['seo_title'] ?? '');
    $tags = sourov_ai_resolve_tags($params);
    $categories = sourov_ai_resolve_categories($params);

    if (!$title || !$content) {
        return new WP_Error('missing_fields', 'Title and content required', ['status' => 400]);
    }

    $post_data = [
        'post_type' => 'post',
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => $schedule ? 'future' : $status,
        'post_author' => get_current_user_id() ?: 1,
        '_sourov_ai_created' => time(),
        '_sourov_ai_conflict_safe' => true,
    ];

    if ($schedule) {
        $post_data['post_date'] = $schedule;
        $post_data['post_date_gmt'] = get_gmt_from_date($schedule);
    }

    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }

    if ($meta_desc) update_post_meta($post_id, '_meta_description', $meta_desc);
    if ($seo_title) update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
    if ($tags) wp_set_object_terms($post_id, $tags, 'post_tag');
    if ($categories) wp_set_object_terms($post_id, $categories, 'category');

    return [
        'success' => true,
        'post_id' => $post_id,
        'status' => $schedule ? 'future' : $status,
        'scheduled' => $schedule ?: null,
        'categories' => $categories,
        'tags' => $tags,
    ];
}

function sourov_ai_post_summary($post) {
    $cats = wp_get_post_categories($post->ID, ['fields' => 'names']);
    $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
    return [
        'id' => $post->ID,
        'title' => $post->post_title,
        'status' => $post->post_status,
        'scheduled' => $post->post_status === 'future' ? $post->post_date : null,
        'categories' => $cats,
        'tags' => $tags,
        'url' => get_permalink($post->ID),
    ];
}

function sourov_ai_get_scheduled($request) {
    $query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'future',
        'orderby' => 'post_date',
        'order' => 'ASC',
        'posts_per_page' => -1,
    ]);

    $posts = [];
    foreach ($query->posts as $p) {
        $posts[] = sourov_ai_post_summary($p);
    }

    return ['posts' => $posts, 'count' => count($posts)];
}

function sourov_ai_get_drafts($request) {
    $per_page = max(1, min(200, (int) ($request->get_param('per_page') ?: 50)));
    $page = max(1, (int) ($request->get_param('page') ?: 1));
    $offset = ($page - 1) * $per_page;

    $query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'draft',
        'orderby' => 'date',
        'order' => 'ASC',
        'posts_per_page' => $per_page,
        'offset' => $offset,
        'no_found_rows' => false,
    ]);

    $posts = [];
    foreach ($query->posts as $p) {
        $posts[] = sourov_ai_post_summary($p);
    }

    return [
        'posts' => $posts,
        'count' => count($posts),
        'total' => (int) $query->found_posts,
        'page' => $page,
        'per_page' => $per_page,
        'pages' => (int) ceil($query->found_posts / $per_page),
    ];
}

function sourov_ai_schedule_drafts($request) {
    $params = $request->get_json_params();
    $interval = max(1, (int) ($params['interval'] ?? $params['interval_minutes'] ?? 60));
    $offset = max(0, (int) ($params['offset'] ?? 0));
    $limit = max(1, min(100, (int) ($params['limit'] ?? 50)));
    $start_hours = max(0, (int) ($params['start_hours'] ?? 1));
    $apply_category = !isset($params['apply_category']) || filter_var($params['apply_category'], FILTER_VALIDATE_BOOLEAN);
    $apply_sections = !isset($params['apply_sections']) || filter_var($params['apply_sections'], FILTER_VALIDATE_BOOLEAN);
    $apply_tags = !isset($params['apply_tags']) || filter_var($params['apply_tags'], FILTER_VALIDATE_BOOLEAN);

    $query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'draft',
        'orderby' => 'date',
        'order' => 'ASC',
        'posts_per_page' => $limit,
        'offset' => $offset,
    ]);

    // Slot 0 = first draft in this batch; global index uses offset for spacing across batches.
    $base = time() + ($start_hours * 3600);
    $results = [];

    foreach ($query->posts as $i => $post) {
        $global_index = $offset + $i;
        $slot = $base + ($global_index * $interval * 60);
        $gmt = gmdate('Y-m-d H:i:s', $slot);
        $local = get_date_from_gmt($gmt);

        wp_update_post([
            'ID' => $post->ID,
            'post_status' => 'future',
            'post_date' => $local,
            'post_date_gmt' => $gmt,
            'edit_date' => true,
        ]);

        if ($apply_category || $apply_sections) {
            $cats = wp_get_post_categories($post->ID);
            if (empty($cats)) {
                $cats = sourov_ai_infer_category_ids($post->post_title, $post->post_content);
                if ($cats) {
                    wp_set_post_categories($post->ID, $cats, false);
                }
            }
        }

        if ($apply_tags) {
            $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
            if (empty($tags)) {
                $words = preg_split('/\s+/', strtolower($post->post_title));
                $tags = array_values(array_filter($words, function ($w) {
                    return strlen($w) > 3;
                }));
                $tags = array_slice($tags, 0, 5);
                if ($tags) {
                    wp_set_post_tags($post->ID, $tags, false);
                }
            }
        }

        clean_post_cache($post->ID);
        $fresh = get_post($post->ID);
        $results[] = sourov_ai_post_summary($fresh);
    }

    $remaining = (int) wp_count_posts('post')->draft;

    return [
        'scheduled' => count($results),
        'interval_minutes' => $interval,
        'offset' => $offset,
        'next_offset' => $offset + count($results),
        'remaining_drafts' => $remaining,
        'results' => $results,
    ];
}

function sourov_ai_delete_post($request) {
    $id = absint($request['id']);
    if (!$id || !get_post($id)) {
        return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    }
    wp_delete_post($id, true);
    return ['success' => true, 'deleted_id' => $id];
}

function sourov_ai_bulk_post($request) {
    $posts = $request->get_json_params() ?: [];
    if (!is_array($posts)) {
        return new WP_Error('invalid', 'Expected array of posts', ['status' => 400]);
    }

    $results = [];
    foreach ($posts as $p) {
        $req = new WP_REST_Request('POST', '/sourov/v1/ai-post');
        $req->set_body_params($p);
        $results[] = sourov_ai_create_post($req);
    }

    return ['created' => count($results), 'results' => $results];
}

function sourov_ai_status($request) {
    $counts = wp_count_posts('post');
    return [
        'online' => true,
        'version' => '1.2',
        'counts' => [
            'published' => (int) ($counts->publish ?? 0),
            'draft' => (int) ($counts->draft ?? 0),
            'scheduled' => (int) ($counts->future ?? 0),
        ],
        'endpoints' => [
            'ai-post' => '/wp-json/sourov/v1/ai-post',
            'drafts' => '/wp-json/sourov/v1/drafts',
            'schedule-drafts' => '/wp-json/sourov/v1/schedule-drafts',
            'scheduled' => '/wp-json/sourov/v1/scheduled',
            'bulk' => '/wp-json/sourov/v1/bulk',
            'status' => '/wp-json/sourov/v1/status',
            'health' => '/wp-json/sourov/v1/health',
        ],
    ];
}

function sourov_ai_health_check($request) {
    $counts = wp_count_posts('post');
    return [
        'status' => 'ok',
        'priority' => SOUROV_AI_PRIORITY,
        'conflict_safe' => true,
        'cache_aware' => true,
        'draft_posts' => (int) ($counts->draft ?? 0),
        'scheduled_posts' => (int) ($counts->future ?? 0),
    ];
}

add_action('admin_menu', function () {
    add_management_page(
        'Sourov AI Controller',
        'Sourov AI',
        'manage_options',
        'sourov-ai',
        'sourov_ai_admin_page'
    );
}, SOUROV_AI_PRIORITY);

function sourov_ai_admin_page() {
    $counts = wp_count_posts('post');
    ?>
    <div class="wrap">
        <h1>Sourov AI Controller v1.2</h1>
        <p><strong>Status:</strong> Running (Priority: <?php echo SOUROV_AI_PRIORITY; ?>, Conflict-Safe: Yes)</p>
        <p>Drafts: <strong><?php echo (int) ($counts->draft ?? 0); ?></strong> |
           Scheduled: <strong><?php echo (int) ($counts->future ?? 0); ?></strong> |
           Published: <strong><?php echo (int) ($counts->publish ?? 0); ?></strong></p>

        <h2>Endpoints</h2>
        <table class="widefat">
            <tr><th>Method</th><th>Endpoint</th><th>Description</th></tr>
            <tr><td><code>POST</code></td><td><code><?php echo esc_url(rest_url('sourov/v1/ai-post')); ?></code></td><td>Create or schedule a post</td></tr>
            <tr><td><code>GET</code></td><td><code><?php echo esc_url(rest_url('sourov/v1/drafts')); ?></code></td><td>List draft posts (paginated)</td></tr>
            <tr><td><code>POST</code></td><td><code><?php echo esc_url(rest_url('sourov/v1/schedule-drafts')); ?></code></td><td>Schedule drafts in batches (interval, offset, limit)</td></tr>
            <tr><td><code>GET</code></td><td><code><?php echo esc_url(rest_url('sourov/v1/scheduled')); ?></code></td><td>List scheduled (future) posts only</td></tr>
            <tr><td><code>GET</code></td><td><code><?php echo esc_url(rest_url('sourov/v1/status')); ?></code></td><td>Health check + post counts</td></tr>
            <tr><td><code>POST</code></td><td><code><?php echo esc_url(rest_url('sourov/v1/bulk')); ?></code></td><td>Bulk create posts (JSON array)</td></tr>
            <tr><td><code>DELETE</code></td><td><code><?php echo esc_url(rest_url('sourov/v1/post/{id}')); ?></code></td><td>Delete post by ID</td></tr>
        </table>

        <h2>Secret API Key (alternative to App Password)</h2>
        <form method="post">
            <?php wp_nonce_field('sourov_ai_nonce'); ?>
            <input type="text" name="secret_key" value="<?php echo esc_attr(get_option('sourov_ai_secret_key')); ?>" placeholder="<?php echo uniqid('sourov_'); ?>">
            <button type="submit" class="button button-primary">Save Key</button>
        </form>
        <?php if ($_POST && check_admin_referer('sourov_ai_nonce')) {
            update_option('sourov_ai_secret_key', sanitize_text_field($_POST['secret_key']));
            echo '<p>Saved.</p>';
        } ?>

        <h2>Real Cron Setup (for exact scheduling)</h2>
        <p>In Hostinger hPanel → Cron Jobs, add:</p>
        <code>curl -s "<?php echo esc_url(site_url('wp-cron.php?doing_wp_cron')); ?>" &gt; /dev/null 2&gt;&amp;1</code>
        <p><strong>Schedule:</strong> every 5 minutes</p>
    </div>
    <?php
}
