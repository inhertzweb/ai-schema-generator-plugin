<?php
/**
 * Settings management with encrypted API keys
 */

namespace IHW_AISG;

class Settings {
	/**
	 * Register admin menu and settings
	 */
	public static function register() {
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_head', [ __CLASS__, 'enqueue_admin_styles' ] );
	}

	/**
	 * Add admin menu
	 */
	public static function add_admin_menu() {
		add_options_page(
			'AI Schema Generator',
			'AI Schema Generator',
			'manage_options',
			'aisg-settings',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Register settings
	 */
	public static function register_settings() {
		// Provider
		register_setting(
			'aisg_settings',
			'aisg_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'claude',
			)
		);

		// API Keys (encrypted)
		register_setting(
			'aisg_settings',
			'aisg_api_key_claude',
			array(
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'encrypt_api_key' ],
				'default'           => '',
			)
		);

		register_setting(
			'aisg_settings',
			'aisg_api_key_gemini',
			array(
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'encrypt_api_key' ],
				'default'           => '',
			)
		);

		// Models
		register_setting(
			'aisg_settings',
			'aisg_model_claude',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'claude-sonnet-4-20250514',
			)
		);

		register_setting(
			'aisg_settings',
			'aisg_model_gemini',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'gemini-3.1-pro-preview',
			)
		);

		// Business brief
		register_setting(
			'aisg_settings',
			'aisg_business_brief',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		// llms.txt URL
		register_setting(
			'aisg_settings',
			'aisg_llms_txt_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => home_url( '/llms.txt' ),
			)
		);

		// Refresh frequency
		register_setting(
			'aisg_settings',
			'aisg_llms_refresh_frequency',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '24h',
			)
		);

		// Post types
		register_setting(
			'aisg_settings',
			'aisg_post_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize_post_types' ],
				'default'           => array( 'post', 'page' ),
			)
		);

		// Auto-generate on publish
		register_setting(
			'aisg_settings',
			'aisg_auto_generate_on_publish',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		// Schema tiers
		register_setting(
			'aisg_settings',
			'aisg_schema_tiers',
			array(
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize_schema_tiers' ],
				'default'           => array( 'tier1' ),
			)
		);

		// Output language
		register_setting(
			'aisg_settings',
			'aisg_output_language',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'auto',
			)
		);

		// Logo URL (fallback if theme doesn't have one)
		register_setting(
			'aisg_settings',
			'aisg_logo_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
	}

	/**
	 * Encrypt API key on save
	 */
	public static function encrypt_api_key( $value ) {
		if ( empty( $value ) ) {
			return '';
		}
		return self::encrypt( $value );
	}

	/**
	 * Sanitize post types
	 */
	public static function sanitize_post_types( $value ) {
		if ( ! is_array( $value ) ) {
			return array( 'post', 'page' );
		}
		return array_map( 'sanitize_text_field', $value );
	}

	/**
	 * Sanitize schema tiers
	 */
	public static function sanitize_schema_tiers( $value ) {
		if ( ! is_array( $value ) ) {
			return array( 'tier1' );
		}
		$valid_tiers = array( 'tier1', 'tier2', 'tier3' );
		return array_intersect( array_map( 'sanitize_text_field', $value ), $valid_tiers );
	}

	/**
	 * Encrypt a value using AES-256-CBC with WordPress salt
	 */
	public static function encrypt( $value ) {
		$cipher   = 'aes-256-cbc';
		$key      = wp_salt( 'auth' );
		// Ensure key is exactly 32 bytes for AES-256
		$key      = substr( hash( 'sha256', $key, true ), 0, 32 );
		$iv       = openssl_random_pseudo_bytes( 16 );
		$encrypted = openssl_encrypt( $value, $cipher, $key, 0, $iv );
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a value
	 */
	public static function decrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}
		$cipher   = 'aes-256-cbc';
		$key      = wp_salt( 'auth' );
		$key      = substr( hash( 'sha256', $key, true ), 0, 32 );
		$data     = base64_decode( $value );
		$iv       = substr( $data, 0, 16 );
		$encrypted = substr( $data, 16 );
		$decrypted = openssl_decrypt( $encrypted, $cipher, $key, 0, $iv );
		return $decrypted ? $decrypted : '';
	}

	/**
	 * Get decrypted API key by provider
	 */
	public static function get_api_key( $provider = null ) {
		if ( ! $provider ) {
			$provider = get_option( 'aisg_provider', 'claude' );
		}
		$key_option = 'aisg_api_key_' . sanitize_text_field( $provider );
		$encrypted  = get_option( $key_option, '' );
		return self::decrypt( $encrypted );
	}

	/**
	 * Get logo URL (from theme or plugin fallback)
	 */
	public static function get_logo_url() {
		// Try theme customizer first
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $logo_url ) {
				return $logo_url;
			}
		}
		// Fallback to plugin setting
		return get_option( 'aisg_logo_url', '' );
	}

	/**
	 * Get option value
	 */
	public static function get( $option, $default = null ) {
		return get_option( 'aisg_' . $option, $default );
	}

	/**
	 * Update option value
	 */
	public static function update( $option, $value ) {
		return update_option( 'aisg_' . $option, $value );
	}

	/**
	 * Render settings page
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		include AISG_PATH . 'admin/views/settings.php';
	}

	/**
	 * Enqueue admin styles
	 */
	public static function enqueue_admin_styles() {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_aisg-settings' !== $screen->id ) {
			return;
		}
		?>
		<style>
			.aisg-settings-table { width: 100%; max-width: 800px; }
			.aisg-settings-table th { text-align: left; padding: 12px; font-weight: bold; border-bottom: 2px solid #ddd; }
			.aisg-settings-table td { padding: 12px; border-bottom: 1px solid #ddd; }
			.aisg-settings-table input[type="text"],
			.aisg-settings-table input[type="password"],
			.aisg-settings-table textarea,
			.aisg-settings-table select { width: 100%; max-width: 400px; padding: 8px; }
			.aisg-settings-table textarea { min-height: 150px; }
			.aisg-hint { font-size: 12px; color: #666; margin-top: 4px; }
			.aisg-success { color: #28a745; margin-top: 10px; }
			.aisg-error { color: #dc3545; margin-top: 10px; }
		</style>
		<?php
	}
}
