<?php
/**
 * Base class for AI providers. Subclasses declare their endpoint, default model,
 * and how to read their key + build auth headers. All HTTP goes through
 * wp_remote_post() (honours WP proxy/SSL config).
 *
 * KEY HANDLING: api_key() reads getenv() / a wp-config.php constant only.
 * If it returns '' the provider is treated as unconfigured — never a hardcoded
 * fallback.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Sourov_AI_Provider {

	/** OpenAI-compatible chat/completions URL for this provider. */
	abstract protected function chat_url();

	/** Default model id when the request omits one. */
	abstract protected function default_model();

	/** Env var name and (optional) wp-config constant that hold this key. */
	abstract protected function key_env();

	/** Human label. */
	abstract public function label();

	/** Read the key from constant first, then env. Never a literal. */
	public function api_key() {
		$name = $this->key_env();
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$val = getenv( $name );
		return $val ? (string) $val : '';
	}

	public function is_configured() {
		return '' !== $this->api_key();
	}

	/** Authorization headers. Override for non-Bearer schemes (see Anthropic). */
	protected function auth_headers() {
		return array( 'Authorization' => 'Bearer ' . $this->api_key() );
	}

	/**
	 * Run a chat completion. $messages is an OpenAI-style array of
	 * {role, content}. Returns array on success or WP_Error.
	 */
	public function chat( array $messages, array $opts = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'sourov_ai_provider_unconfigured',
				sprintf( '%s key not set. Define %s in wp-config.php or server env.', $this->label(), $this->key_env() ),
				array( 'status' => 503 )
			);
		}

		$body = array_merge(
			array(
				'model'    => isset( $opts['model'] ) && $opts['model'] ? $opts['model'] : $this->default_model(),
				'messages' => $messages,
			),
			$this->extra_body( $opts )
		);

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
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'sourov_ai_upstream_error',
				sprintf( '%s returned HTTP %d', $this->label(), $code ),
				array( 'status' => 502, 'upstream' => $data )
			);
		}

		return $this->normalize( $data );
	}

	/** Provider-specific extra body fields (temperature, max_tokens…). */
	protected function extra_body( array $opts ) {
		$extra = array();
		if ( isset( $opts['temperature'] ) ) {
			$extra['temperature'] = (float) $opts['temperature'];
		}
		if ( isset( $opts['max_tokens'] ) ) {
			$extra['max_tokens'] = (int) $opts['max_tokens'];
		}
		return $extra;
	}

	/** Reduce provider response to {text, model, raw}. Override as needed. */
	protected function normalize( $data ) {
		$text = '';
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			$text = $data['choices'][0]['message']['content'];
		}
		return array(
			'text'  => $text,
			'model' => isset( $data['model'] ) ? $data['model'] : null,
			'raw'   => $data,
		);
	}
}
