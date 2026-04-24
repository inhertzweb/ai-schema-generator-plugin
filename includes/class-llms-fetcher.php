<?php
/**
 * Fetch and cache llms.txt context
 */

namespace IHW_AISG;

class LlmsFetcher {
	const CACHE_KEY = 'aisg_llms_cache';

	/**
	 * Get llms.txt context (cached)
	 */
	public static function get_context() {
		// Check transient cache
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch from URL
		$url      = Settings::get( 'llms_txt_url', home_url( '/llms.txt' ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log_error( 'Failed to fetch llms.txt: ' . $response->get_error_message() );
			// Fall back to empty context
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return '';
		}

		// Cache based on refresh frequency setting
		$frequency = Settings::get( 'llms_refresh_frequency', '24h' );
		$ttl       = self::frequency_to_seconds( $frequency );
		set_transient( self::CACHE_KEY, $body, $ttl );

		return $body;
	}

	/**
	 * Convert frequency string to seconds
	 */
	private static function frequency_to_seconds( $frequency ) {
		switch ( $frequency ) {
			case '24h':
				return 24 * HOUR_IN_SECONDS;
			case '7d':
				return 7 * DAY_IN_SECONDS;
			case 'manual':
				return 365 * DAY_IN_SECONDS; // Very long TTL for manual refresh
			default:
				return 24 * HOUR_IN_SECONDS;
		}
	}

	/**
	 * Invalidate cache
	 */
	public static function invalidate_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Log error
	 */
	private static function log_error( $message ) {
		$log = get_option( 'aisg_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'type'    => 'error',
			'message' => $message,
		);
		// Keep only last 1000 entries
		if ( count( $log ) > 1000 ) {
			$log = array_slice( $log, -1000 );
		}
		update_option( 'aisg_log', $log );
	}
}
