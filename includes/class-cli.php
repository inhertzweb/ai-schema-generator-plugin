<?php
/**
 * WP-CLI Commands for AI Schema Generator
 */

namespace IHW_AISG;

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

class CLI {
	/**
	 * Show diagnostics for schema conflicts
	 *
	 * ## EXAMPLES
	 *
	 *     wp aisg diagnostics
	 *
	 * @when after_wp_load
	 */
	public function diagnostics( $args, $assoc_args ) {
		\WP_CLI::line( "AI Schema Generator Diagnostics\n" );

		// Active plugins
		$report = Diagnostics::get_report();

		\WP_CLI::line( "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" );
		\WP_CLI::line( "Active Competing Plugins:" );
		\WP_CLI::line( "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" );

		if ( ! empty( $report['active_competitors'] ) ) {
			foreach ( $report['active_competitors'] as $file => $name ) {
				\WP_CLI::warning( "$name ($file)" );
			}
		} else {
			\WP_CLI::success( "✓ No competing plugins detected" );
		}

		\WP_CLI::line( "\n" );
		\WP_CLI::line( "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" );
		\WP_CLI::line( "Disabled Hooks (" . count( $report['disabled_hooks'] ) . "):" );
		\WP_CLI::line( "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" );

		if ( ! empty( $report['disabled_hooks'] ) ) {
			foreach ( $report['disabled_hooks'] as $hook ) {
				\WP_CLI::success( "✓ {$hook['function']} (priority: {$hook['priority']})" );
			}
		} else {
			\WP_CLI::warning( "ℹ️ No hooks have been disabled yet." );
			\WP_CLI::warning( "   This could mean competing plugins aren't active, or hooks weren't registered as expected." );
		}

		\WP_CLI::line( "\n" );
		\WP_CLI::line( "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" );
		\WP_CLI::line( "Registered Hooks on wp_head (" . count( $report['hook_details'] ) . "):" );
		\WP_CLI::line( "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" );

		foreach ( $report['hook_details'] as $hook ) {
			$priority_str = sprintf( "Priority %4d", $hook['priority'] );
			\WP_CLI::line( "$priority_str → {$hook['callback']}" );
		}

		\WP_CLI::line( "\n" );
		\WP_CLI::success( "Diagnostics complete" );
	}

	/**
	 * View recent logs
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of log entries to show (default: 50)
	 *
	 * ## EXAMPLES
	 *
	 *     wp aisg logs
	 *     wp aisg logs --limit=100
	 *
	 * @when after_wp_load
	 */
	public function logs( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 50;

		$log = get_option( 'aisg_log', array() );
		$log = array_slice( $log, -$limit );

		if ( empty( $log ) ) {
			\WP_CLI::warning( "No log entries found" );
			return;
		}

		\WP_CLI::line( "Recent AI Schema Generator Log Entries (last $limit):\n" );

		foreach ( $log as $entry ) {
			$time = $entry['time'] ?? 'unknown';
			$type = $entry['type'] ?? 'unknown';
			$status = $entry['status'] ?? 'info';

			if ( 'success' === $status ) {
				$symbol = '✓';
			} elseif ( 'error' === $status ) {
				$symbol = '✗';
			} else {
				$symbol = 'ℹ️';
			}

			if ( empty( $entry['message'] ) ) {
				$post_title = $entry['post_title'] ?? 'unknown';
				$message = 'success' === $status ? "Schema generated for: $post_title" : 'Unknown';
			} else {
				$message = $entry['message'];
			}

			\WP_CLI::line( sprintf( "%s [%s] %s: %s", $symbol, $time, $type, $message ) );
		}
	}
}

\WP_CLI::add_command( 'aisg', 'IHW_AISG\CLI' );
