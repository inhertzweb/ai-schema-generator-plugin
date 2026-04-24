<?php
/**
 * Claude AI Provider - Anthropic Messages API
 */

namespace IHW_AISG;

class ClaudeProvider implements AIProviderInterface {
	private static $instance = null;
	const ENDPOINT        = 'https://api.anthropic.com/v1/messages';
	const API_VERSION     = '2023-06-01';
	const MAX_RETRIES     = 3;
	const RETRY_DELAYS    = array( 2, 4, 8 ); // seconds

	/**
	 * Singleton instance
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Generate schema using Claude
	 */
	public function generate( $prompt, $system ) {
		$api_key = Settings::get_api_key( 'claude' );
		if ( empty( $api_key ) ) {
			throw new \Exception( 'Claude API key not configured' );
		}

		$headers = array(
			'x-api-key'              => $api_key,
			'anthropic-version'      => self::API_VERSION,
			'content-type'           => 'application/json',
		);

		$body = array(
			'model'      => Settings::get( 'model_claude', 'claude-sonnet-4-20250514' ),
			'max_tokens' => 4096,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		// Retry logic with exponential backoff
		$attempt = 0;
		while ( $attempt < self::MAX_RETRIES ) {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'headers'   => $headers,
					'body'      => wp_json_encode( $body ),
					'timeout'   => 60,
					'sslverify' => true,
				)
			);

			$status_code = wp_remote_retrieve_response_code( $response );

			// Success
			if ( 200 === $status_code ) {
				return $this->parse_response( $response );
			}

			// Retryable errors (429 rate limit, 503 service unavailable)
			if ( in_array( $status_code, array( 429, 503 ), true ) && $attempt < self::MAX_RETRIES - 1 ) {
				$delay = self::RETRY_DELAYS[ $attempt ];
				sleep( $delay );
				$attempt++;
				continue;
			}

			// Non-retryable error
			$error_body = wp_remote_retrieve_body( $response );
			$error_msg = ! empty( $error_body ) ? substr( $error_body, 0, 200 ) : 'Empty response body';
			if ( is_wp_error( $response ) ) {
				$error_msg = $response->get_error_message();
			}
			throw new \Exception( "Claude API error ($status_code): " . $error_msg );
		}

		throw new \Exception( 'Claude API request failed after ' . self::MAX_RETRIES . ' retries' );
	}

	/**
	 * Parse Claude response
	 */
	private function parse_response( $response ) {
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['content'][0]['text'] ) ) {
			throw new \Exception( 'Invalid Claude response format' );
		}

		return $data['content'][0]['text'];
	}

	/**
	 * Get model name
	 */
	public function get_model_name() {
		return Settings::get( 'model_claude', 'claude-sonnet-4-20250514' );
	}

	/**
	 * Test connection
	 */
	public function test_connection() {
		try {
			$this->generate( 'Say "ok"', 'You are a helpful assistant.' );
			return true;
		} catch ( \Exception $e ) {
			$log = (array) get_option( 'aisg_log', array() );
			$log[] = array(
				'time'    => current_time( 'mysql' ),
				'type'    => 'test_connection',
				'status'  => 'error',
				'message' => 'Claude API test failed: ' . $e->getMessage(),
			);
			update_option( 'aisg_log', array_slice( $log, -1000 ) );
			return false;
		}
	}
}
