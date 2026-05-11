<?php
/**
 * Plugin Name: AI Schema Generator
 * Description: Auto-generate JSON-LD schema markup for every page/post using Claude or Gemini AI
 * Version: 1.0.0
 * Author: Inhertzweb Agency
 * Requires PHP: 8.1
 * Requires Plugins: elementor
 * License: GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
if ( ! defined( 'AISG_PATH' ) ) {
	define( 'AISG_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'AISG_URL' ) ) {
	define( 'AISG_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'AISG_VERSION' ) ) {
	define( 'AISG_VERSION', '1.0.0' );
}

// Load classes
require_once AISG_PATH . 'includes/class-settings.php';
require_once AISG_PATH . 'includes/class-llms-fetcher.php';
require_once AISG_PATH . 'includes/class-ai-engine.php';
require_once AISG_PATH . 'includes/class-claude-provider.php';
require_once AISG_PATH . 'includes/class-gemini-provider.php';
require_once AISG_PATH . 'includes/class-gemini-models.php';
require_once AISG_PATH . 'includes/class-page-analyzer.php';
require_once AISG_PATH . 'includes/class-faq-extractor.php';
require_once AISG_PATH . 'includes/class-schema-builder.php';
require_once AISG_PATH . 'includes/class-bulk-processor.php';
require_once AISG_PATH . 'includes/class-output-injector.php';
require_once AISG_PATH . 'includes/class-schema-conflict-resolver.php';
require_once AISG_PATH . 'includes/class-diagnostics.php';
require_once AISG_PATH . 'includes/class-cli.php';
require_once AISG_PATH . 'admin/class-admin-menu.php';

/**
 * Initialize the plugin
 */
function ihw_aisg_init() {
	IHW_AISG\SchemaConflictResolver::register();
	IHW_AISG\Settings::register();
	IHW_AISG\OutputInjector::register();
	IHW_AISG\AdminMenu::register();
	IHW_AISG\BulkProcessor::register();
}

add_action( 'plugins_loaded', 'ihw_aisg_init' );

/**
 * Activation hook: seed default options and flush rewrite rules
 */
register_activation_hook(
	__FILE__,
	function() {
		$defaults = array(
			'aisg_provider'                    => 'claude',
			'aisg_model_claude'                => 'claude-sonnet-4-20250514',
			'aisg_model_gemini'                => 'gemini-3.1-pro-preview',
			'aisg_business_brief'              => '',
			'aisg_llms_txt_url'                => home_url( '/llms.txt' ),
			'aisg_llms_refresh_frequency'      => '24h',
			'aisg_post_types'                  => array( 'post', 'page' ),
			'aisg_auto_generate_on_publish'    => false,
			'aisg_schema_tiers'                => array( 'tier1' ),
			'aisg_output_language'             => 'auto',
			'aisg_logo_url'                    => '',
			'aisg_disable_theme_schema'        => false,
		);

		foreach ( $defaults as $key => $default ) {
			if ( ! get_option( $key ) ) {
				add_option( $key, $default );
			}
		}

		// Disable theme schema markup to avoid duplicates
		update_option( 'aisg_disable_theme_schema', true );
	}
);

/**
 * Deactivation hook: optionally remove all generated schemas
 */
register_deactivation_hook(
	__FILE__,
	function() {
		// Clear transients and caches
		delete_transient( 'aisg_llms_cache' );
		delete_option( 'aisg_bulk_progress' );
		delete_option( 'aisg_log' );
		delete_option( 'aisg_disable_theme_schema' );
	}
);
