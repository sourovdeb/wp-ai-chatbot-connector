<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** OpenRouter — OpenAI-compatible aggregator. */
class Sourov_AI_OpenRouter extends Sourov_AI_Provider {
	public function label() { return 'OpenRouter'; }
	protected function chat_url() { return 'https://openrouter.ai/api/v1/chat/completions'; }
	protected function default_model() { return 'openrouter/auto'; }
	protected function key_env() { return 'SOUROV_OPENROUTER_API_KEY'; }

	protected function auth_headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key(),
			// OpenRouter attribution headers (optional but recommended).
			'HTTP-Referer'  => home_url( '/' ),
			'X-Title'       => get_bloginfo( 'name' ),
		);
	}
}
