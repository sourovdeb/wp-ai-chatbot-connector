<?php
/**
 * Gateway authentication.
 *
 * Two accepted paths, both without ever putting a secret in a URL:
 *   1. Header `X-Sourov-Key` matching the `sourov_ai_gateway_key` option
 *      (constant-time compare via hash_equals).
 *   2. A logged-in WordPress user with `manage_options`.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sourov_AI_Auth {

	/** REST permission_callback. Returns true or a WP_Error. */
	public static function check( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$expected = (string) get_option( 'sourov_ai_gateway_key', '' );
		if ( '' === $expected ) {
			return new WP_Error(
				'sourov_ai_locked',
				'Gateway key is not configured. Set the sourov_ai_gateway_key option (from SOUROV_AI_GATEWAY_KEY env) or authenticate as an administrator.',
				array( 'status' => 503 )
			);
		}

		$provided = (string) $request->get_header( 'x_sourov_key' ); // WP maps X-Sourov-Key -> x_sourov_key
		if ( '' !== $provided && hash_equals( $expected, $provided ) ) {
			return true;
		}

		return new WP_Error(
			'sourov_ai_forbidden',
			'Missing or invalid X-Sourov-Key header.',
			array( 'status' => 401 )
		);
	}
}
