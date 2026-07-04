<?php
/**
 * Plugin Name: Sourov AI Gateway
 * Description: Secure multi-provider AI gateway (Mistral, xAI/Grok, OpenRouter, OpenClaw, Anthropic Claude) exposed over the WordPress REST API. No secrets in code — every provider key is read from wp-config.php constants or server env.
 * Version:     1.0.0
 * Author:      sourovdeb
 * License:     GPL-2.0-or-later
 *
 * SECURITY MODEL (read AGENTS.md / SECURITY.md in the repo root):
 *  - No provider key is ever stored in this file or the database. Keys come from
 *    getenv() / wp-config.php constants only.
 *  - Gateway auth uses an X-Sourov-Key request HEADER compared with hash_equals()
 *    against the `sourov_ai_gateway_key` option, OR a logged-in user with the
 *    `manage_options` capability. Never a query-string key.
 *  - There is deliberately NO arbitrary-file-write and NO code-eval endpoint, and
 *    no "node execution layer". Those are remote-code-execution backdoors — see
 *    SECURITY.md for why the previous deploy.php pattern was removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOUROV_AI_GATEWAY_VERSION', '1.0.0' );
define( 'SOUROV_AI_GATEWAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOUROV_AI_GATEWAY_NS', 'sourov-ai/v1' );

require_once SOUROV_AI_GATEWAY_DIR . 'includes/class-rate-limit.php';
require_once SOUROV_AI_GATEWAY_DIR . 'includes/class-auth.php';
require_once SOUROV_AI_GATEWAY_DIR . 'includes/class-provider.php';
require_once SOUROV_AI_GATEWAY_DIR . 'includes/class-mistral.php';
require_once SOUROV_AI_GATEWAY_DIR . 'includes/class-xai.php';
require_once SOUROV_AI_GATEWAY_DIR . 'includes/class-openrouter.php';
require_once SOUROV_AI_GATEWAY_DIR . 'includes/class-openclaw.php';
require_once SOUROV_AI_GATEWAY_DIR . 'includes/class-anthropic.php';
require_once SOUROV_AI_GATEWAY_DIR . 'includes/class-gateway-handler.php';

/**
 * The provider registry. Each entry maps a URL slug to its handler class.
 * Adding a provider = add a class + one line here. "Add claude" is the
 * Sourov_AI_Anthropic entry below.
 */
function sourov_ai_gateway_providers() {
	return array(
		'mistral'    => 'Sourov_AI_Mistral',
		'xai'        => 'Sourov_AI_XAI',        // Grok
		'openrouter' => 'Sourov_AI_OpenRouter',
		'openclaw'   => 'Sourov_AI_OpenClaw',
		'anthropic'  => 'Sourov_AI_Anthropic',  // Claude
	);
}

add_action( 'rest_api_init', function () {
	( new Sourov_AI_Gateway_Handler() )->register_routes();
} );

register_activation_hook( __FILE__, function () {
	// Seed the gateway key from env if the admin has not set one yet.
	// We NEVER hardcode it; if the env var is absent the gateway stays locked
	// to manage_options users until a key is set.
	if ( ! get_option( 'sourov_ai_gateway_key' ) ) {
		$env = getenv( 'SOUROV_AI_GATEWAY_KEY' );
		if ( $env ) {
			update_option( 'sourov_ai_gateway_key', $env, false );
		}
	}
} );
