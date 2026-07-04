<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** xAI / Grok — OpenAI-compatible API. */
class Sourov_AI_XAI extends Sourov_AI_Provider {
	public function label() { return 'xAI (Grok)'; }
	protected function chat_url() { return 'https://api.x.ai/v1/chat/completions'; }
	protected function default_model() { return 'grok-2-latest'; }
	protected function key_env() { return 'SOUROV_XAI_API_KEY'; }
}
