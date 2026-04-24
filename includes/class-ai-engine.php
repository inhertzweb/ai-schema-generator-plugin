<?php
/**
 * AI Engine - routes to appropriate provider (Claude or Gemini)
 */

namespace IHW_AISG;

interface AIProviderInterface {
	/**
	 * Generate schema using the AI provider
	 */
	public function generate( $prompt, $system );

	/**
	 * Get model name
	 */
	public function get_model_name();

	/**
	 * Test connection to provider
	 */
	public function test_connection();
}

class AIEngine {
	private static $instance = null;

	/**
	 * Factory method - returns appropriate provider instance
	 */
	public static function make() {
		$provider = Settings::get( 'provider', 'claude' );

		if ( 'gemini' === $provider ) {
			return GeminiProvider::instance();
		}

		return ClaudeProvider::instance();
	}

	/**
	 * Test connection to the selected provider
	 */
	public static function test_connection() {
		try {
			$provider = self::make();
			return $provider->test_connection();
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
