<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anthropic (Claude) — this is the "Add claude" provider.
 *
 * Anthropic's Messages API differs from the OpenAI-compatible providers:
 *   - Auth header is `x-api-key` (not Bearer) plus an `anthropic-version`.
 *   - `max_tokens` is REQUIRED.
 *   - A `system` prompt is a top-level field, not a message with role=system.
 *   - The reply is in `content[0].text`, not `choices[0].message.content`.
 *
 * Default model is a current Claude 5 id; override per request with `model`.
 */
class Sourov_AI_Anthropic extends Sourov_AI_Provider {

	const API_VERSION = '2023-06-01';

	public function label() { return 'Anthropic (Claude)'; }
	protected function chat_url() { return 'https://api.anthropic.com/v1/messages'; }
	protected function default_model() { return 'claude-sonnet-5'; }
	protected function key_env() { return 'SOUROV_ANTHROPIC_API_KEY'; }

	protected function auth_headers() {
		return array(
			'x-api-key'         => $this->api_key(),
			'anthropic-version' => self::API_VERSION,
		);
	}

	/**
	 * Override chat() to build the Anthropic-shaped body: pull any system
	 * message out to the top level, require max_tokens.
	 */
	public function chat( array $messages, array $opts = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'sourov_ai_provider_unconfigured',
				sprintf( '%s key not set. Define %s in wp-config.php or server env.', $this->label(), $this->key_env() ),
				array( 'status' => 503 )
			);
		}

		$system = '';
		$turns  = array();
		foreach ( $messages as $m ) {
			if ( isset( $m['role'] ) && 'system' === $m['role'] ) {
				$system .= ( '' === $system ? '' : "\n" ) . $m['content'];
			} else {
				$turns[] = array(
					'role'    => isset( $m['role'] ) ? $m['role'] : 'user',
					'content' => isset( $m['content'] ) ? $m['content'] : '',
				);
			}
		}

		$body = array(
			'model'      => isset( $opts['model'] ) && $opts['model'] ? $opts['model'] : $this->default_model(),
			'max_tokens' => isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 1024,
			'messages'   => $turns,
		);
		if ( '' !== $system ) {
			$body['system'] = $system;
		}
		if ( isset( $opts['temperature'] ) ) {
			$body['temperature'] = (float) $opts['temperature'];
		}

		$response = wp_remote_post(
			$this->chat_url(),
			array(
				'timeout' => (int) apply_filters( 'sourov_ai_http_timeout', 60 ),
				'headers' => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$this->auth_headers()
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'sourov_ai_upstream_error',
				sprintf( '%s returned HTTP %d', $this->label(), $code ),
				array( 'status' => 502, 'upstream' => $data )
			);
		}

		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		return array(
			'text'  => $text,
			'model' => isset( $data['model'] ) ? $data['model'] : null,
			'raw'   => $data,
		);
	}
}
