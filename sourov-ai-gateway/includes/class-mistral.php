<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sourov_AI_Mistral extends Sourov_AI_Provider {
	public function label() { return 'Mistral'; }
	protected function chat_url() { return 'https://api.mistral.ai/v1/chat/completions'; }
	protected function default_model() { return 'mistral-large-latest'; }
	protected function key_env() { return 'SOUROV_MISTRAL_API_KEY'; }
}
