<?php
/**
 * Diagnostic utilities for debugging schema conflicts
 */

namespace IHW_AISG;

class Diagnostics {
	/**
	 * Get detailed diagnostics report
	 */
	public static function get_report() {
		$report = array(
			'active_competitors' => self::get_active_competing_plugins(),
			'disabled_hooks'     => \IHW_AISG\SchemaConflictResolver::get_disabled_conflicts(),
			'hook_details'       => self::get_wp_head_hooks(),
			'our_schema'         => self::get_our_schema(),
		);

		return $report;
	}

	/**
	 * Get list of active competing SEO plugins
	 */
	private static function get_active_competing_plugins() {
		$competitors = array(
			'wordpress-seo/wp-seo.php'                      => 'Yoast SEO',
			'seo-by-rank-math/rank-math.php'                => 'RankMath',
			'all-in-one-seo-pack/all_in_one_seo_pack.php'   => 'All-in-One SEO',
			'seopress/seopress.php'                         => 'SEOPress',
			'autodescription/autodescription.php'           => 'The SEO Framework',
			'elementor/elementor.php'                       => 'Elementor',
			'divi-builder/divi-builder.php'                 => 'Divi',
		);

		$active = array();
		foreach ( $competitors as $plugin_file => $plugin_name ) {
			if ( is_plugin_active( $plugin_file ) ) {
				$active[ $plugin_file ] = $plugin_name;
			}
		}

		return $active;
	}

	/**
	 * Get details about hooks registered on wp_head
	 */
	private static function get_wp_head_hooks() {
		global $wp_filter;

		if ( ! isset( $wp_filter['wp_head'] ) ) {
			return array();
		}

		$hooks = array();
		foreach ( $wp_filter['wp_head'] as $priority => $callbacks ) {
			foreach ( $callbacks as $hook_id => $callback_data ) {
				$callback = $callback_data['function'];
				$hooks[] = array(
					'priority' => $priority,
					'callback' => self::callback_to_string( $callback ),
					'hook_id'  => $hook_id,
				);
			}
		}

		// Sort by priority (highest first)
		usort( $hooks, function( $a, $b ) {
			return $b['priority'] - $a['priority'];
		} );

		return $hooks;
	}

	/**
	 * Get our generated schema for current post
	 */
	private static function get_our_schema() {
		if ( is_singular() ) {
			$post_id = get_the_ID();
			return get_post_meta( $post_id, '_aisg_schema_json', true );
		}

		if ( is_front_page() ) {
			$front_page_id = intval( get_option( 'page_on_front' ) );
			if ( $front_page_id > 0 ) {
				return get_post_meta( $front_page_id, '_aisg_schema_json', true );
			}

			return get_option( 'aisg_homepage_schema', '' );
		}

		return null;
	}

	/**
	 * Convert callback to readable string
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
	 * Output HTML debug panel (for admin use)
	 */
	public static function output_debug_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = self::get_report();
		?>
		<div style="background: #f5f5f5; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-top: 20px; font-family: monospace; font-size: 12px;">
			<h4>🔧 Debug Panel (Admin Only)</h4>

			<details style="margin-bottom: 15px;">
				<summary style="cursor: pointer; font-weight: bold;">Active Competing Plugins (<?php echo count( $report['active_competitors'] ); ?>)</summary>
				<pre style="background: white; padding: 10px; border-radius: 4px; overflow-x: auto;">
<?php
if ( ! empty( $report['active_competitors'] ) ) {
	foreach ( $report['active_competitors'] as $file => $name ) {
		echo "✓ $name ($file)\n";
	}
} else {
	echo "✓ No competing plugins detected\n";
}
?>
				</pre>
			</details>

			<details style="margin-bottom: 15px;">
				<summary style="cursor: pointer; font-weight: bold;">Disabled Hooks (<?php echo count( $report['disabled_hooks'] ); ?>)</summary>
				<pre style="background: white; padding: 10px; border-radius: 4px; overflow-x: auto;">
<?php
if ( ! empty( $report['disabled_hooks'] ) ) {
	foreach ( $report['disabled_hooks'] as $hook ) {
		echo "✓ {$hook['function']} (priority: {$hook['priority']})\n";
	}
} else {
	echo "ℹ️ No hooks have been disabled yet. This could mean:\n";
	echo "   1. No competing plugins are active\n";
	echo "   2. Hooks were registered differently than expected\n";
	echo "   3. Check 'Registered Hooks' panel below\n";
}
?>
				</pre>
			</details>

			<details style="margin-bottom: 15px;">
				<summary style="cursor: pointer; font-weight: bold;">Registered Hooks on wp_head (<?php echo count( $report['hook_details'] ); ?>)</summary>
				<pre style="background: white; padding: 10px; border-radius: 4px; overflow-x: auto; max-height: 400px;">
<?php
foreach ( $report['hook_details'] as $hook ) {
	$priority_str = sprintf( "Priority %4d", $hook['priority'] );
	echo "$priority_str → {$hook['callback']}\n";
}
?>
				</pre>
			</details>

			<details>
				<summary style="cursor: pointer; font-weight: bold;">Our Generated Schema</summary>
				<pre style="background: white; padding: 10px; border-radius: 4px; overflow-x: auto; max-height: 400px;">
<?php
if ( ! empty( $report['our_schema'] ) ) {
	echo htmlspecialchars( $report['our_schema'], ENT_QUOTES, 'UTF-8' );
} else {
	echo "No schema generated yet\n";
}
?>
				</pre>
			</details>
		</div>
		<?php
	}
}
