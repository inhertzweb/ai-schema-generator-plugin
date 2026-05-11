<?php
/**
 * Gemini Models fetcher - get available models from Google API
 */

namespace IHW_AISG;

class GeminiModels {
	const CACHE_KEY = 'aisg_gemini_models';
	const CACHE_TTL = 86400; // 24 hours
	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

	/**
	 * Get list of available Gemini models
	 */
	public static function get_models( $api_key = '' ) {
		if ( empty( $api_key ) ) {
			$api_key = Settings::get_api_key( 'gemini' );
		}

		if ( empty( $api_key ) ) {
			return self::get_fallback_models();
		}

		// Try cache first
		$cached = get_transient( self::CACHE_KEY );
		if ( $cached !== false ) {
			return $cached;
		}

		// Fetch from API
		$models = self::fetch_from_api( $api_key );
		if ( ! empty( $models ) ) {
			set_transient( self::CACHE_KEY, $models, self::CACHE_TTL );
			return $models;
		}

		// Fallback if fetch fails
		return self::get_fallback_models();
	}

	/**
	 * Fetch models from Google API
	 */
	private static function fetch_from_api( $api_key ) {
		$url = self::ENDPOINT . '?key=' . urlencode( $api_key ) . '&pageSize=1000';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'GeminiModels: WP Error - ' . $response->get_error_message() );
			return array();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			error_log( 'GeminiModels: HTTP ' . $status_code . ' - ' . substr( $body, 0, 200 ) );
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['models'] ) || ! is_array( $data['models'] ) ) {
			return array();
		}

		$models = array();
		foreach ( $data['models'] as $model ) {
			// Only include models that support generateContent
			if ( ! isset( $model['name'] ) || ! isset( $model['supportedGenerationMethods'] ) ) {
				continue;
			}

			if ( ! in_array( 'generateContent', (array) $model['supportedGenerationMethods'], true ) ) {
				continue;
			}

			// Extract model name (remove 'models/' prefix)
			$model_name = str_replace( 'models/', '', $model['name'] );

			// Skip non-text models (image, tts, audio, robotics, deep-research, etc.)
			$skip_keywords = array( 'image', 'tts', 'lyria', 'robotics', 'deep-research', 'banana' );
			$skip_model = false;
			foreach ( $skip_keywords as $keyword ) {
				if ( strpos( $model_name, $keyword ) !== false ) {
					$skip_model = true;
					break;
				}
			}
			if ( $skip_model ) {
				continue;
			}

			$display_name = self::get_display_name( $model_name );
			$models[ $model_name ] = $display_name;
		}

		return $models;
	}

	/**
	 * Get human-readable display name for model
	 */
	private static function get_display_name( $model_name ) {
		$display_names = array(
			'gemini-2.5-pro'                    => 'Gemini 2.5 Pro',
			'gemini-2.5-flash'                  => 'Gemini 2.5 Flash (Consigliato)',
			'gemini-2.0-flash'                  => 'Gemini 2.0 Flash',
			'gemini-2.0-flash-001'              => 'Gemini 2.0 Flash 001',
			'gemini-2.0-flash-lite-001'         => 'Gemini 2.0 Flash Lite 001',
			'gemini-2.0-flash-lite'             => 'Gemini 2.0 Flash Lite',
			'gemini-2.5-flash-lite'             => 'Gemini 2.5 Flash Lite',
			'gemini-flash-latest'               => 'Gemini Flash (Latest)',
			'gemini-flash-lite-latest'          => 'Gemini Flash Lite (Latest)',
			'gemini-pro-latest'                 => 'Gemini Pro (Latest)',
			'gemini-3-pro-preview'              => 'Gemini 3 Pro (Preview)',
			'gemini-3-flash-preview'            => 'Gemini 3 Flash (Preview)',
			'gemini-3.1-pro-preview'            => 'Gemini 3.1 Pro (Preview)',
			'gemini-3.1-pro-preview-customtools' => 'Gemini 3.1 Pro Custom Tools (Preview)',
			'gemini-3.1-flash-lite-preview'     => 'Gemini 3.1 Flash Lite (Preview)',
			'gemini-3.1-flash-lite'             => 'Gemini 3.1 Flash Lite',
		);

		return isset( $display_names[ $model_name ] ) ? $display_names[ $model_name ] : $model_name;
	}

	/**
	 * Get fallback models if API fetch fails
	 */
	private static function get_fallback_models() {
		return array(
			'gemini-2.5-flash'              => 'Gemini 2.5 Flash (Consigliato)',
			'gemini-2.5-pro'                => 'Gemini 2.5 Pro',
			'gemini-2.0-flash'              => 'Gemini 2.0 Flash',
			'gemini-3.1-pro-preview'        => 'Gemini 3.1 Pro (Preview)',
			'gemini-3.1-flash-lite-preview' => 'Gemini 3.1 Flash Lite (Preview)',
		);
	}

	/**
	 * Clear models cache
	 */
	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
