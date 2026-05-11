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
        } elseif ( 'all' === $mode ) {
            // For 'all' mode, we want to regenerate everything including existing schemas
            // No filtering needed - we'll process all posts
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

		// Store post IDs for batch processing
		update_option(
			'aisg_bulk_queue',
			array_values( $post_ids )
		);

        // Store progress
        update_option(
            'aisg_bulk_progress',
            array(
                'total'      => count( $post_ids ),
                'done'       => 0,
                'errors'     => 0,
                'started'    => current_time( 'mysql' ),
                'status'     => 'running',
                'method'     => 'wp-cron', // Track which method is processing
                'mode'       => $mode, // Store the mode for batch processing
            )
        );

		// Schedule first batch via WP-Cron (will process all batches)
		// Use a unique timestamp to avoid conflicts
		wp_schedule_single_event( time() + 1, 'aisg_process_batch' );

		// Also set up AJAX fallback in case WP-Cron doesn't trigger
		// Store a "trigger time" to detect if WP-Cron failed
		update_option( 'aisg_cron_trigger_time', time() );

		return count( $post_ids );
	}

	/**
	 * Process next batch from queue (called via AJAX polling as fallback)
	 * This is used when WP-Cron is not available or has failed
	 */
	public static function process_next_batch() {
		$queue = get_option( 'aisg_bulk_queue', array() );
		$progress = get_option( 'aisg_bulk_progress', array() );
		$start_time = time();
		$timeout = 40; // Max 40 seconds per AJAX request (safer than 50)

		if ( empty( $queue ) ) {
			$progress['status']    = 'complete';
			$progress['completed'] = current_time( 'mysql' );
			update_option( 'aisg_bulk_progress', $progress );
			return false;
		}

		// Check if WP-Cron has taken over (check if method changed in progress)
		if ( isset( $progress['method'] ) && 'wp-cron' === $progress['method'] ) {
			$cron_trigger = get_option( 'aisg_cron_trigger_time', 0 );
			// If WP-Cron was triggered recently, let it handle it
			if ( $cron_trigger > ( time() - 60 ) ) {
				// WP-Cron is active, return what we have
				return ! empty( $queue );
			}
		}

		// Mark that AJAX is handling this
		$progress['method'] = 'ajax';
		$progress['last_ajax_call'] = current_time( 'mysql' );

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

            // If mode is 'all', delete existing schema before regenerating
            if ( isset( $progress['mode'] ) && 'all' === $progress['mode'] ) {
                delete_post_meta( $post_id, '_aisg_schema_json' );
                delete_post_meta( $post_id, '_aisg_schema_generated_at' );
            }

            $result = SchemaBuilder::build( $post_id );

            $progress['done'] = isset( $progress['done'] ) ? $progress['done'] + 1 : 1;
            if ( ! $result ) {
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
	 * Process a batch of posts via WP-Cron
	 * This handles the entire queue, scheduling itself for the next batch if needed
	 */
	public static function process_batch( $post_ids = array() ) {
		$queue = get_option( 'aisg_bulk_queue', array() );

		if ( empty( $queue ) ) {
			// Queue is empty, mark as complete
			$progress = get_option( 'aisg_bulk_progress', array() );
			$progress['status']    = 'complete';
			$progress['completed'] = current_time( 'mysql' );
			update_option( 'aisg_bulk_progress', $progress );
			return;
		}

		$progress = get_option( 'aisg_bulk_progress', array() );

		// Process next batch
		$batch = array_slice( $queue, 0, self::BATCH_SIZE );
		$remaining = array_slice( $queue, self::BATCH_SIZE );

		// Update queue
		if ( empty( $remaining ) ) {
			delete_option( 'aisg_bulk_queue' );
		} else {
			update_option( 'aisg_bulk_queue', $remaining );
		}

        // Process each post in batch
        foreach ( $batch as $post_id ) {
            // Add 0.5 second delay between requests
            usleep( 500000 );

            // If mode is 'all', delete existing schema before regenerating
            if ( isset( $progress['mode'] ) && 'all' === $progress['mode'] ) {
                delete_post_meta( $post_id, '_aisg_schema_json' );
                delete_post_meta( $post_id, '_aisg_schema_generated_at' );
            }

            $result = SchemaBuilder::build( $post_id );

            $progress['done'] = isset( $progress['done'] ) ? $progress['done'] + 1 : 1;
            if ( ! $result ) {
                $progress['errors'] = isset( $progress['errors'] ) ? $progress['errors'] + 1 : 1;
            }
        }

		update_option( 'aisg_bulk_progress', $progress );

		// Schedule next batch if there are more posts
		if ( ! empty( $remaining ) ) {
			// Schedule next batch after a short delay (5 seconds)
			wp_schedule_single_event( time() + 5, 'aisg_process_batch' );
		} else {
			// All done
			$progress['status']    = 'complete';
			$progress['completed'] = current_time( 'mysql' );
			update_option( 'aisg_bulk_progress', $progress );
		}
	}

	/**
	 * Get bulk progress
	 */
	public static function get_progress() {
		return get_option( 'aisg_bulk_progress', array() );
	}
}
