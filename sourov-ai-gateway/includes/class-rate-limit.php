<?php
/**
 * Simple per-caller sliding-window rate limiter backed by WordPress transients.
 * Not a substitute for a real WAF, but stops a leaked key from being hammered.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sourov_AI_Rate_Limit {

	const DEFAULT_LIMIT  = 60;   // requests
	const DEFAULT_WINDOW = 60;   // seconds

	/**
	 * @return true|WP_Error  true if under the limit, WP_Error (429) otherwise.
	 */
	public static function check( $caller_id ) {
		$limit  = (int) apply_filters( 'sourov_ai_rate_limit', self::DEFAULT_LIMIT );
		$window = (int) apply_filters( 'sourov_ai_rate_window', self::DEFAULT_WINDOW );

		$key   = 'sourov_ai_rl_' . md5( (string) $caller_id );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'sourov_ai_rate_limited',
				sprintf( 'Rate limit exceeded (%d requests / %ds).', $limit, $window ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/** Stable, non-reversible identifier for the caller. */
	public static function caller_id( WP_REST_Request $request ) {
		if ( is_user_logged_in() ) {
			return 'user:' . get_current_user_id();
		}
		$key = (string) $request->get_header( 'x_sourov_key' );
		if ( '' !== $key ) {
			return 'key:' . substr( hash( 'sha256', $key ), 0, 16 );
		}
		return 'ip:' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown' );
	}
}
