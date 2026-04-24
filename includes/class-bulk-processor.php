<?php
/**
 * Bulk schema generation with WP-Cron queue
 */

namespace IHW_AISG;

class BulkProcessor {
	const BATCH_SIZE = 5;

	/**
	 * Register hooks
	 */
	public static function register() {
		add_action( 'aisg_process_batch', [ __CLASS__, 'process_batch' ] );
		add_action( 'publish_post', [ __CLASS__, 'auto_generate_on_publish' ] );
	}

	/**
	 * Auto-generate on publish if enabled
	 */
	public static function auto_generate_on_publish( $post_id ) {
		if ( ! Settings::get( 'auto_generate_on_publish' ) ) {
			return;
		}

		$post = get_post( $post_id );
		$enabled_types = Settings::get( 'post_types', array( 'post', 'page' ) );

		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		SchemaBuilder::build( $post_id );
	}

	/**
	 * Enqueue all posts for schema generation
	 */
	public static function enqueue_all( $mode = 'missing' ) {
		$post_types = Settings::get( 'post_types', array( 'post', 'page' ) );
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		// Query posts
		$args = array(
			'post_type'      => $post_types,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		);

		// Filter by mode
		if ( 'missing' === $mode ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query
				array(
					'key'     => '_aisg_schema_json',
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( 'outdated' === $mode ) {
			// Compare post_modified with last schema generation
			// This is complex with meta queries, so we'll filter after
		}

		$query = new \WP_Query( $args );
		$post_ids = $query->posts;

		if ( 'outdated' === $mode ) {
			$post_ids = array_filter(
				$post_ids,
				function ( $post_id ) {
					$generated_at = get_post_meta( $post_id, '_aisg_schema_generated_at', true );
					if ( empty( $generated_at ) ) {
						return false;
					}
					$post = get_post( $post_id );
					return strtotime( $post->post_modified ) > strtotime( $generated_at );
				}
			);
		}

		if ( empty( $post_ids ) ) {
			return 0;
		}

		// Store post IDs for batch processing (no WP-Cron, will process via AJAX polling)
		update_option(
			'aisg_bulk_queue',
			array_values( $post_ids )
		);

		// Store progress
		update_option(
			'aisg_bulk_progress',
			array(
				'total'    => count( $post_ids ),
				'done'     => 0,
				'errors'   => 0,
				'started'  => current_time( 'mysql' ),
				'status'   => 'running',
			)
		);

		return count( $post_ids );
	}

	/**
	 * Process next batch from queue (called via AJAX polling)
	 */
	public static function process_next_batch() {
		$queue = get_option( 'aisg_bulk_queue', array() );
		$start_time = time();
		$timeout = 50; // Max 50 seconds per AJAX request

		if ( empty( $queue ) ) {
			$progress = get_option( 'aisg_bulk_progress', array() );
			$progress['status'] = 'complete';
			$progress['completed'] = current_time( 'mysql' );
			update_option( 'aisg_bulk_progress', $progress );
			return false;
		}

		// Get next batch
		$batch = array_slice( $queue, 0, self::BATCH_SIZE );
		$remaining = array_slice( $queue, self::BATCH_SIZE );

		// Update queue
		if ( empty( $remaining ) ) {
			delete_option( 'aisg_bulk_queue' );
		} else {
			update_option( 'aisg_bulk_queue', $remaining );
		}

		// Process batch with timeout protection
		$progress = get_option( 'aisg_bulk_progress', array() );

		foreach ( $batch as $post_id ) {
			// Check timeout - if we're close to timing out, stop and reschedule rest
			if ( time() - $start_time > $timeout ) {
				// Put remaining posts back in queue
				if ( ! empty( $remaining ) ) {
					update_option( 'aisg_bulk_queue', $remaining );
				}
				update_option( 'aisg_bulk_progress', $progress );
				return true; // Signal that more batches need processing
			}

			$result = SchemaBuilder::build( $post_id );

			if ( $result ) {
				$progress['done'] = isset( $progress['done'] ) ? $progress['done'] + 1 : 1;
			} else {
				$progress['errors'] = isset( $progress['errors'] ) ? $progress['errors'] + 1 : 1;
			}
		}

		update_option( 'aisg_bulk_progress', $progress );

		// Check if all batches done
		if ( isset( $progress['total'] ) && isset( $progress['done'] ) ) {
			if ( $progress['done'] + $progress['errors'] >= $progress['total'] ) {
				$progress['status']    = 'complete';
				$progress['completed'] = current_time( 'mysql' );
				update_option( 'aisg_bulk_progress', $progress );
			}
		}

		// Return true if more batches to process
		return ! empty( $remaining );
	}

	/**
	 * Process a batch of posts
	 */
	public static function process_batch( $post_ids = array() ) {
		if ( empty( $post_ids ) ) {
			return;
		}

		$progress = get_option( 'aisg_bulk_progress', array() );

		foreach ( $post_ids as $post_id ) {
			// Add 1 second delay between requests to respect rate limits
			sleep( 1 );

			$result = SchemaBuilder::build( $post_id );

			if ( $result ) {
				$progress['done'] = isset( $progress['done'] ) ? $progress['done'] + 1 : 1;
			} else {
				$progress['errors'] = isset( $progress['errors'] ) ? $progress['errors'] + 1 : 1;
			}
		}

		// Update progress
		update_option( 'aisg_bulk_progress', $progress );

		// Check if all batches done
		if ( isset( $progress['total'] ) && isset( $progress['done'] ) ) {
			if ( $progress['done'] + $progress['errors'] >= $progress['total'] ) {
				$progress['status']    = 'complete';
				$progress['completed'] = current_time( 'mysql' );
				update_option( 'aisg_bulk_progress', $progress );
			}
		}
	}

	/**
	 * Get bulk progress
	 */
	public static function get_progress() {
		return get_option( 'aisg_bulk_progress', array() );
	}
}
