<?php
/**
 * Gemini AI Provider - Google Generative AI
 */

namespace IHW_AISG;

class GeminiProvider implements AIProviderInterface {
	private static $instance = null;
	const ENDPOINT        = 'https://generativelanguage.googleapis.com/v1beta/models';
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
	 * Generate schema using Gemini
	 */
	public function generate( $prompt, $system ) {
		$api_key = Settings::get_api_key( 'gemini' );
		if ( empty( $api_key ) ) {
			throw new \Exception( 'Gemini API key not configured' );
		}

		$model = Settings::get( 'model_gemini', 'gemini-3.1-pro-preview' );
		$url   = self::ENDPOINT . '/' . $model . ':generateContent?key=' . urlencode( $api_key );

		$body = array(
			'systemInstruction' => array(
				'parts' => array(
					array( 'text' => $system ),
				),
			),
			'contents' => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'maxOutputTokens' => 4096,
			),
		);

		// Retry logic with exponential backoff
		$attempt = 0;
		while ( $attempt < self::MAX_RETRIES ) {
			$response = wp_remote_post(
				$url,
				array(
					'headers'   => array(
						'content-type' => 'application/json',
					),
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

			// Retryable errors
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
			throw new \Exception( "Gemini API error ($status_code): " . $error_msg );
		}

		throw new \Exception( 'Gemini API request failed after ' . self::MAX_RETRIES . ' retries' );
	}

	/**
	 * Parse Gemini response
	 */
	private function parse_response( $response ) {
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			throw new \Exception( 'Invalid Gemini response format' );
		}

		return $data['candidates'][0]['content']['parts'][0]['text'];
	}

	/**
	 * Get model name
	 */
	public function get_model_name() {
		return Settings::get( 'model_gemini', 'gemini-3.1-pro-preview' );
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
				'message' => 'Gemini API test failed: ' . $e->getMessage(),
			);
			update_option( 'aisg_log', array_slice( $log, -1000 ) );
			return false;
		}
	}
}
