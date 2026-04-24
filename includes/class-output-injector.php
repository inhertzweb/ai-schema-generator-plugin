<?php
/**
 * Inject schema markup into <head> and provide REST endpoints
 */

namespace IHW_AISG;

class OutputInjector {
	/**
	 * Register hooks
	 */
	public static function register() {
		// Run our schema injection at very high priority (before most other plugins)
		// Higher priority numbers run first in WordPress
		add_action( 'wp_head', [ __CLASS__, 'inject_schema' ], 999 );
		// Add plugin-specific filters to prevent duplicate schema output
		add_action( 'plugins_loaded', [ __CLASS__, 'disable_competing_schema_filters' ], 6 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_endpoints' ] );
	}

	/**
	 * Inject schema into <head>
	 */
	public static function inject_schema() {
		// For singular posts/pages
		if ( is_singular() ) {
			self::inject_singular_schema();
		}

		// For homepage
		if ( is_front_page() ) {
			self::inject_homepage_schema();
		}
	}

	/**
	 * Disable competing schema generation via plugin-specific filters
	 * This is a failsafe in case remove_action() doesn't work
	 */
	public static function disable_competing_schema_filters() {
		// Yoast SEO - disable structured data output
		if ( defined( 'WPSEO_VERSION' ) ) {
			// Yoast uses filters to control JSON-LD output
			add_filter( 'wpseo_json_ld_output', '__return_false' );
			add_filter( 'wpseo_frontend_presenter', '__return_false' );
			add_filter( 'wpseo_output_structured_data', '__return_false' );
		}

		// RankMath - disable schema output
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			add_filter( 'rank_math_json_ld', '__return_false' );
			add_filter( 'rank_math_schema_output', '__return_false' );
		}

		// All-in-One SEO
		if ( class_exists( 'AIOSEO' ) ) {
			add_filter( 'aioseo_output_json_ld_markup', '__return_false' );
		}

		// SEOPress
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			add_filter( 'seopress_display_json_ld', '__return_false' );
		}
	}

	/**
	 * Inject schema for singular post/page
	 */
	private static function inject_singular_schema() {
		$post_id = get_the_ID();
		$schema_json = get_post_meta( $post_id, '_aisg_schema_json', true );

		if ( ! empty( $schema_json ) ) {
			echo '<script type="application/ld+json">';
			echo wp_json_encode( json_decode( $schema_json ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			echo '</script>';
		}
	}

	/**
	 * Inject homepage schema
	 */
	private static function inject_homepage_schema() {
		$front_page_id = intval( get_option( 'page_on_front' ) );

		if ( $front_page_id > 0 ) {
			$schema_json = get_post_meta( $front_page_id, '_aisg_schema_json', true );
			if ( ! empty( $schema_json ) ) {
				echo '<script type="application/ld+json">';
				echo wp_json_encode( json_decode( $schema_json ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
				echo '</script>';
				return;
			}
		}

		// Fallback to homepage schema option
		$homepage_schema = get_option( 'aisg_homepage_schema', '' );
		if ( ! empty( $homepage_schema ) ) {
			echo '<script type="application/ld+json">';
			echo wp_json_encode( json_decode( $homepage_schema ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			echo '</script>';
		}
	}

	/**
	 * Register REST endpoints
	 */
	public static function register_rest_endpoints() {
		// GET /wp-json/aisg/v1/schema/{post_id}
		register_rest_route(
			'aisg/v1',
			'/schema/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_schema' ],
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_id' => array(
						'validate_callback' => function( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		// POST /wp-json/aisg/v1/regenerate/{post_id}
		register_rest_route(
			'aisg/v1',
			'/regenerate/(?P<post_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'regenerate_schema' ],
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'post_id' => array(
						'validate_callback' => function( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * GET schema endpoint
	 */
	public static function get_schema( $request ) {
		$post_id = intval( $request['post_id'] );
		$schema_json = get_post_meta( $post_id, '_aisg_schema_json', true );

		if ( empty( $schema_json ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'No schema found' ),
				404
			);
		}

		return new \WP_REST_Response( json_decode( $schema_json ) );
	}

	/**
	 * POST regenerate endpoint
	 */
	public static function regenerate_schema( $request ) {
		$post_id = intval( $request['post_id'] );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_REST_Response(
				array( 'error' => 'Post not found' ),
				404
			);
		}

		try {
			$schema_json = SchemaBuilder::build( $post_id );

			if ( null === $schema_json ) {
				return new \WP_REST_Response(
					array( 'error' => 'Failed to generate schema' ),
					500
				);
			}

			return new \WP_REST_Response(
				array(
					'success' => true,
					'schema'  => json_decode( $schema_json ),
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_REST_Response(
				array( 'error' => $e->getMessage() ),
				500
			);
		}
	}
}
