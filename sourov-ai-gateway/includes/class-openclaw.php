<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenClaw — OpenAI-compatible. Endpoint is configurable via env because
 * OpenClaw deployments are often self-hosted; falls back to the public base.
 */
class Sourov_AI_OpenClaw extends Sourov_AI_Provider {
	public function label() { return 'OpenClaw'; }
	protected function default_model() { return 'openclaw-default'; }
	protected function key_env() { return 'SOUROV_OPENCLAW_API_KEY'; }

	protected function chat_url() {
		$base = getenv( 'SOUROV_OPENCLAW_BASE_URL' );
		$base = $base ? rtrim( $base, '/' ) : 'https://api.openclaw.ai/v1';
		return $base . '/chat/completions';
	}
}
