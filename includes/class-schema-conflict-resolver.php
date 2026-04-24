<?php
/**
 * Detect and disable conflicting schema markup from other plugins/themes
 */

namespace IHW_AISG;

class SchemaConflictResolver {
	/**
	 * Known schema-emitting plugins/themes and their hooks to disable
	 */
	private static $conflicts = array(
		// Yoast SEO
		'yoast_seo' => array(
			'hooks'  => array(
				array( 'wp_head', 'wpseo_json_ld_output', 99 ),
				array( 'wp_head', 'wpseo_output_structured_data', 21 ),
				array( 'wp_head', 'wpseo_frontend_presenter', 10 ),
			),
			'plugin' => 'wordpress-seo/wp-seo.php',
		),
		// RankMath
		'rank_math' => array(
			'hooks'  => array(
				array( 'wp_head', 'rank_math_json_ld', 50 ),
				array( 'wp_head', 'rank_math_schema_output', 10 ),
				array( 'wp_head', 'rank_math_output_schema', 10 ),
			),
			'plugin' => 'seo-by-rank-math/rank-math.php',
		),
		// All-in-One SEO
		'all_in_one_seo' => array(
			'hooks'  => array(
				array( 'wp_head', 'aioseo_output_json_ld_markup', 10 ),
				array( 'wp_head', 'aioseo_output_schema_markup', 10 ),
			),
			'plugin' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
		),
		// SEOPress
		'seopress' => array(
			'hooks'  => array(
				array( 'wp_head', 'seopress_display_json_ld', 100 ),
				array( 'wp_head', 'seopress_json_ld', 1 ),
			),
			'plugin' => 'seopress/seopress.php',
		),
		// The SEO Framework
		'tsf' => array(
			'hooks'  => array(
				array( 'wp_head', 'tsf_output_structured_data', 9 ),
				array( 'wp_head', 'tsf_json_ld_output', 10 ),
			),
			'plugin' => 'autodescription/autodescription.php',
		),
		// Elementor Schema
		'elementor' => array(
			'hooks'  => array(
				array( 'wp_head', 'elementor_print_schema_markup', 1 ),
				array( 'wp_head', 'elementor_output_schema', 10 ),
			),
			'plugin' => 'elementor/elementor.php',
		),
		// Genesis Framework
		'genesis' => array(
			'hooks'  => array(
				array( 'wp_head', 'genesis_output_json_ld', 5 ),
			),
			'plugin' => 'genesis/genesis.php',
		),
		// Divi/Extra
		'divi' => array(
			'hooks'  => array(
				array( 'wp_head', 'et_output_schema', 10 ),
				array( 'wp_head', 'et_json_ld_output', 10 ),
			),
			'plugin' => 'divi-builder/divi-builder.php',
		),
		// Schema.org Plugin
		'schema_org_plugin' => array(
			'hooks'  => array(
				array( 'wp_head', 'schema_output_json_ld', 10 ),
			),
			'plugin' => 'schema-app/schema-app.php',
		),
	);

	/**
	 * Register conflict resolver
	 */
	public static function register() {
		// Run early to catch and disable conflicting hooks
		add_action( 'plugins_loaded', [ __CLASS__, 'disable_conflicts' ], 5 );
		add_action( 'after_setup_theme', [ __CLASS__, 'disable_conflicts' ], 5 );
	}

	/**
	 * Disable conflicting schema hooks from other plugins
	 */
	public static function disable_conflicts() {
		global $wp_filter;

		$disabled_hooks = array();

		// Get all hooks on wp_head (before we try to remove anything)
		$wp_head_hooks = isset( $wp_filter['wp_head'] ) ? $wp_filter['wp_head'] : array();

		// Log what hooks are currently registered
		self::log_message( 'Hooks on wp_head before conflict resolution: ' . count( (array) $wp_head_hooks ) );

		foreach ( self::$conflicts as $plugin_key => $conflict ) {
			// Check if plugin is active
			if ( ! is_plugin_active( $conflict['plugin'] ) ) {
				continue;
			}

			$plugin_name = basename( dirname( $conflict['plugin'] ) );
			self::log_message( "Attempting to remove hooks for plugin: $plugin_name" );

			// Get current hooks registered
			if ( isset( $wp_filter['wp_head'] ) ) {
				foreach ( $wp_filter['wp_head'] as $priority => $callbacks ) {
					foreach ( $callbacks as $hook_id => $callback_data ) {
						$callback = $callback_data['function'];

						// Check if this callback belongs to the current plugin
						$callback_str = self::callback_to_string( $callback );
						if ( self::is_plugin_callback( $callback, $plugin_name ) ) {
							// Try to remove it
							if ( remove_action( 'wp_head', $callback, $priority ) ) {
								$disabled_hooks[] = array(
									'plugin'   => $conflict['plugin'],
									'hook'     => 'wp_head',
									'function' => $callback_str,
									'priority' => $priority,
								);
								self::log_message( "✓ Removed hook: $callback_str at priority $priority" );
							}
						}
					}
				}
			}

			// Also try the hardcoded function names as fallback
			foreach ( $conflict['hooks'] as $hook_info ) {
				list( $hook, $function, $priority ) = $hook_info;

				if ( remove_action( $hook, $function, $priority ) ) {
					$disabled_hooks[] = array(
						'plugin'   => $conflict['plugin'],
						'hook'     => $hook,
						'function' => $function,
						'priority' => $priority,
					);
					self::log_message( "✓ Removed hardcoded hook: $function at priority $priority" );
				}
			}
		}

		// Log disabled conflicts
		if ( ! empty( $disabled_hooks ) ) {
			self::log_disabled_conflicts( $disabled_hooks );
		} else {
			self::log_message( "No conflicting hooks were successfully removed. This may indicate mismatched callback names." );
		}

		// Disable theme schema if present
		self::disable_theme_schema();
	}

	/**
	 * Disable theme schema
	 */
	private static function disable_theme_schema() {
		// Check for theme schema files
		$theme_schema_files = array(
			'inc/schema.php',
			'inc/schema-json-ld.php',
			'lib/schema.php',
			'includes/schema.php',
		);

		$theme_dir = get_template_directory();

		foreach ( $theme_schema_files as $file ) {
			$file_path = $theme_dir . '/' . $file;
			if ( file_exists( $file_path ) ) {
				// Remove hooks from theme schema file
				remove_action( 'wp_head', 'ihw_output_schema_org', 99 );
				remove_action( 'wp_head', 'ihw_output_schema_local_business', 10 );

				// Log theme schema found
				self::log_message( 'Theme schema file found and disabled: ' . $file );
			}
		}
	}

	/**
	 * Log disabled conflicts
	 */
	private static function log_disabled_conflicts( $disabled_hooks ) {
		foreach ( $disabled_hooks as $entry ) {
			$message = sprintf(
				'Disabled schema hook: %s → %s (priority: %d)',
				$entry['hook'],
				$entry['function'],
				$entry['priority']
			);
			self::log_message( $message );
		}

		// Also store the list of disabled conflicts
		update_option(
			'aisg_disabled_conflicts',
			$disabled_hooks,
			false
		);
	}

	/**
	 * Log a message
	 */
	private static function log_message( $message ) {
		$log = get_option( 'aisg_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'type'    => 'conflict_resolver',
			'message' => $message,
		);

		// Keep only last 1000 entries
		if ( count( $log ) > 1000 ) {
			$log = array_slice( $log, -1000 );
		}

		update_option( 'aisg_log', $log );
	}

	/**
	 * Get list of detected and disabled conflicts
	 */
	public static function get_disabled_conflicts() {
		return get_option( 'aisg_disabled_conflicts', array() );
	}

	/**
	 * Check if a specific plugin's schema was disabled
	 */
	public static function is_plugin_schema_disabled( $plugin_slug ) {
		$disabled = self::get_disabled_conflicts();
		foreach ( $disabled as $entry ) {
			if ( false !== stripos( $entry['plugin'], $plugin_slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get summary of disabled conflicts for admin display
	 */
	public static function get_summary() {
		$disabled = self::get_disabled_conflicts();
		$summary  = array();

		foreach ( $disabled as $entry ) {
			$plugin_name = basename( dirname( $entry['plugin'] ) );
			if ( ! isset( $summary[ $plugin_name ] ) ) {
				$summary[ $plugin_name ] = 0;
			}
			$summary[ $plugin_name ]++;
		}

		return $summary;
	}

	/**
	 * Convert a callback to a readable string
	 */
	private static function callback_to_string( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		} elseif ( is_array( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];
			return $class . '::' . $callback[1];
		} elseif ( is_object( $callback ) ) {
			return get_class( $callback ) . '::__invoke';
		}
		return '[unknown callback]';
	}

	/**
	 * Check if a callback belongs to a plugin
	 */
	private static function is_plugin_callback( $callback, $plugin_name ) {
		$callback_str = self::callback_to_string( $callback );

		// Check for known plugin class prefixes and function names
		$plugin_keywords = array(
			'yoast'      => array( 'Yoast', 'wpseo', 'yoast' ),
			'rank-math'  => array( 'RankMath', 'rank_math' ),
			'all-in-one-seo-pack' => array( 'aioseo', 'AIOSEO', 'aio' ),
			'seopress'   => array( 'SeoPress', 'seopress' ),
			'autodescription' => array( 'TSF', 'autodescription' ),
			'elementor'  => array( 'Elementor', 'elementor' ),
			'divi-builder' => array( 'Divi', 'Elegant' ),
			'schema'     => array( 'Schema' ),
		);

		foreach ( $plugin_keywords as $plugin_key => $keywords ) {
			if ( strpos( $plugin_name, $plugin_key ) !== false || strpos( $plugin_key, $plugin_name ) !== false ) {
				foreach ( $keywords as $keyword ) {
					if ( stripos( $callback_str, $keyword ) !== false ) {
						return true;
					}
				}
			}
		}

		return false;
	}
}
