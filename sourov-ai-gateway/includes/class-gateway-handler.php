<?php
/**
 * Registers the REST routes and dispatches to the right provider.
 *
 * Routes (namespace sourov-ai/v1):
 *   GET  /status                    public health check (no secrets)
 *   GET  /providers                 list configured providers (auth)
 *   POST /{provider}/chat           chat completion (auth + rate limit)
 *   GET  /{provider}/models         provider default/config (auth)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sourov_AI_Gateway_Handler {

	public function register_routes() {
		$ns = SOUROV_AI_GATEWAY_NS;

		register_rest_route( $ns, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'status' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/providers', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'providers' ),
			'permission_callback' => array( 'Sourov_AI_Auth', 'check' ),
		) );

		register_rest_route( $ns, '/(?P<provider>[a-z0-9_-]+)/chat', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'chat' ),
			'permission_callback' => array( 'Sourov_AI_Auth', 'check' ),
			'args'                => array(
				'provider' => array( 'required' => true ),
			),
		) );

		register_rest_route( $ns, '/(?P<provider>[a-z0-9_-]+)/models', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'models' ),
			'permission_callback' => array( 'Sourov_AI_Auth', 'check' ),
		) );
	}

	private function provider_instance( $slug ) {
		$map = sourov_ai_gateway_providers();
		if ( ! isset( $map[ $slug ] ) ) {
			return new WP_Error( 'sourov_ai_unknown_provider', 'Unknown provider: ' . $slug, array( 'status' => 404 ) );
		}
		$class = $map[ $slug ];
		return new $class();
	}

	public function status( WP_REST_Request $request ) {
		$configured = array();
		foreach ( sourov_ai_gateway_providers() as $slug => $class ) {
			$configured[ $slug ] = ( new $class() )->is_configured();
		}
		return rest_ensure_response( array(
			'ok'         => true,
			'plugin'     => 'sourov-ai-gateway',
			'version'    => SOUROV_AI_GATEWAY_VERSION,
			'providers'  => $configured, // booleans only — no keys
			'gateway_key_set' => (bool) get_option( 'sourov_ai_gateway_key' ),
		) );
	}

	public function providers( WP_REST_Request $request ) {
		$out = array();
		foreach ( sourov_ai_gateway_providers() as $slug => $class ) {
			$p = new $class();
			$out[] = array(
				'slug'       => $slug,
				'label'      => $p->label(),
				'configured' => $p->is_configured(),
			);
		}
		return rest_ensure_response( $out );
	}

	public function models( WP_REST_Request $request ) {
		$p = $this->provider_instance( $request['provider'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		// Providers expose default model; live model listing is provider-specific
		// and intentionally not proxied here to keep the surface small.
		return rest_ensure_response( array(
			'provider'      => $request['provider'],
			'label'         => $p->label(),
			'configured'    => $p->is_configured(),
		) );
	}

	public function chat( WP_REST_Request $request ) {
		$limited = Sourov_AI_Rate_Limit::check( Sourov_AI_Rate_Limit::caller_id( $request ) );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$p = $this->provider_instance( $request['provider'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}

		$params   = $request->get_json_params();
		$messages = isset( $params['messages'] ) ? $params['messages'] : null;

		// Accept a single {prompt} as shorthand.
		if ( ! $messages && ! empty( $params['prompt'] ) ) {
			$messages = array( array( 'role' => 'user', 'content' => (string) $params['prompt'] ) );
		}

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return new WP_Error( 'sourov_ai_bad_request', 'Provide `messages` (array) or `prompt` (string).', array( 'status' => 400 ) );
		}

		$opts = array();
		foreach ( array( 'model', 'temperature', 'max_tokens' ) as $k ) {
			if ( isset( $params[ $k ] ) ) {
				$opts[ $k ] = $params[ $k ];
			}
		}

		$result = $p->chat( $messages, $opts );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}
