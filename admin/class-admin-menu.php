<?php
/**
 * Admin menu and meta boxes
 */

namespace IHW_AISG;

class AdminMenu {
	/**
	 * Register hooks
	 */
	public static function register() {
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_pages' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_action( 'wp_ajax_aisg_regenerate', [ __CLASS__, 'ajax_regenerate_schema' ] );
		add_action( 'wp_ajax_aisg_delete_schema', [ __CLASS__, 'ajax_delete_schema' ] );
		add_action( 'wp_ajax_aisg_bulk_generate', [ __CLASS__, 'ajax_bulk_generate' ] );
		add_action( 'wp_ajax_aisg_process_batch', [ __CLASS__, 'ajax_process_batch' ] );
        add_action( 'wp_ajax_aisg_get_progress', [ __CLASS__, 'ajax_get_progress' ] );
        add_action( 'wp_ajax_aisg_clear_all_schemas', [ __CLASS__, 'ajax_clear_all_schemas' ] );
	}

	/**
	 * Add admin pages
	 */
	public static function add_admin_pages() {
		add_submenu_page(
			'options-general.php',
			'AI Schema Generator - Dashboard',
			'AI Schema Dashboard',
			'manage_options',
			'aisg-dashboard',
			[ __CLASS__, 'render_dashboard' ]
		);
	}

	/**
	 * Add meta boxes
	 */
	public static function add_meta_boxes() {
		$post_types = Settings::get( 'post_types', array( 'post', 'page' ) );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'aisg_meta_box',
				'AI Schema Markup',
				[ __CLASS__, 'render_meta_box' ],
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render meta box
	 */
	public static function render_meta_box( $post ) {
		$schema_json = get_post_meta( $post->ID, '_aisg_schema_json', true );
		$generated_at = get_post_meta( $post->ID, '_aisg_schema_generated_at', true );
		$post_modified = $post->post_modified;

		// Determine if outdated
		$is_outdated = ! empty( $generated_at ) && strtotime( $post_modified ) > strtotime( $generated_at );

		?>
		<div style="margin-bottom: 12px;">
			<?php if ( ! empty( $schema_json ) ) : ?>
				<span style="display: inline-block; background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 3px; font-size: 12px; margin-bottom: 8px;">
					✓ Schema generato
				</span>
				<?php if ( $is_outdated ) : ?>
					<span style="display: inline-block; background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 3px; font-size: 12px;">
						⚠ Non aggiornato
					</span>
				<?php endif; ?>
			<?php else : ?>
				<span style="display: inline-block; background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 3px; font-size: 12px;">
					✗ Mancante
				</span>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $generated_at ) ) : ?>
			<p style="margin-bottom: 12px; font-size: 12px; color: #666;">
				Generato: <strong><?php echo esc_html( $generated_at ); ?></strong>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $schema_json ) ) : ?>
			<details style="margin-bottom: 12px; font-size: 12px;">
				<summary style="cursor: pointer; font-weight: bold;">
					Anteprima JSON
				</summary>
				<textarea style="width: 100%; height: 200px; margin-top: 8px; font-family: monospace; font-size: 11px; readonly;"><?php echo esc_textarea( $schema_json ); ?></textarea>
			</details>
		<?php endif; ?>

		<div style="display: flex; gap: 8px;">
			<button class="button button-primary" id="aisg-regenerate" style="flex: 1;">
				Rigenera
			</button>
			<?php if ( ! empty( $schema_json ) ) : ?>
				<button class="button" id="aisg-delete" style="flex: 1;">
					Elimina
				</button>
			<?php endif; ?>
		</div>

		<script>
			document.getElementById('aisg-regenerate')?.addEventListener('click', function(e) {
				e.preventDefault();
				const button = this;
				button.disabled = true;
				button.textContent = 'Generando...';

				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams({
						action: 'aisg_regenerate',
						post_id: <?php echo intval( $post->ID ); ?>,
						nonce: '<?php echo esc_attr( wp_create_nonce( 'aisg_regenerate' ) ); ?>'
					})
				})
				.then(response => response.json())
				.then(data => {
					button.disabled = false;
					if (data.success) {
						button.textContent = 'Rigenera';
						location.reload();
					} else {
						alert('Errore: ' + (data.data?.message || 'Riprova'));
						button.textContent = 'Rigenera';
					}
				})
				.catch(err => {
					alert('Errore di rete: ' + err);
					button.disabled = false;
					button.textContent = 'Rigenera';
				});
			});

			document.getElementById('aisg-delete')?.addEventListener('click', function(e) {
				e.preventDefault();
				if (!confirm('Vuoi davvero eliminare lo schema?')) return;

				const button = this;
				button.disabled = true;

				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams({
						action: 'aisg_delete_schema',
						post_id: <?php echo intval( $post->ID ); ?>,
						nonce: '<?php echo esc_attr( wp_create_nonce( 'aisg_delete_schema' ) ); ?>'
					})
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						location.reload();
					} else {
						alert('Errore: ' + (data.data?.message || 'Riprova'));
						button.disabled = false;
					}
				});
			});
		</script>
		<?php
	}

	/**
	 * AJAX regenerate schema
	 */
	public static function ajax_regenerate_schema() {
		check_ajax_referer( 'aisg_regenerate', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID' ), 400 );
		}

		$result = SchemaBuilder::build( $post_id );

		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Schema generato con successo' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Errore nella generazione dello schema' ), 500 );
		}
	}

	/**
	 * AJAX delete schema
	 */
	public static function ajax_delete_schema() {
		check_ajax_referer( 'aisg_delete_schema', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID' ), 400 );
		}

		delete_post_meta( $post_id, '_aisg_schema_json' );
		delete_post_meta( $post_id, '_aisg_schema_generated_at' );

		wp_send_json_success( array( 'message' => 'Schema eliminato' ) );
	}

	/**
	 * AJAX bulk generate
	 */
	public static function ajax_bulk_generate() {
		check_ajax_referer( 'aisg_bulk_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$mode = sanitize_text_field( $_POST['mode'] ?? 'missing' );
		$count = BulkProcessor::enqueue_all( $mode );

		wp_send_json_success( array(
			'message' => sprintf( '%d pagine in coda per generazione', $count ),
			'count'   => $count,
		) );
	}

	/**
	 * AJAX process next batch
	 */
	public static function ajax_process_batch() {
		check_ajax_referer( 'aisg_process_batch', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$has_more = BulkProcessor::process_next_batch();
		$progress = BulkProcessor::get_progress();

		wp_send_json_success( array_merge( $progress, array( 'has_more' => $has_more ) ) );
	}

	/**
	 * AJAX get progress
	 */
	public static function ajax_get_progress() {
		check_ajax_referer( 'aisg_get_progress', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$progress = BulkProcessor::get_progress();

        wp_send_json_success( $progress );
    }

    /**
     * AJAX clear all schemas (debug function)
     */
    public static function ajax_clear_all_schemas() {
        check_ajax_referer( 'aisg_clear_all_schemas', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $post_types = Settings::get( 'post_types', array( 'post', 'page' ) );
        if ( empty( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        // Query all posts with schemas
        $args = array(
            'post_type'      => $post_types,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query
                array(
                    'key'     => '_aisg_schema_json',
                    'compare' => 'EXISTS',
                ),
            ),
        );

        $query = new \WP_Query( $args );
        $post_ids = $query->posts;
        $count = count( $post_ids );

        if ( empty( $post_ids ) ) {
            wp_send_json_success( array(
                'message' => 'Nessuno schema trovato da eliminare.',
                'count'   => 0,
            ) );
        }

        // Delete schemas from all posts
        foreach ( $post_ids as $post_id ) {
            delete_post_meta( $post_id, '_aisg_schema_json' );
            delete_post_meta( $post_id, '_aisg_schema_generated_at' );
        }

        // Clear bulk processing data
        delete_option( 'aisg_bulk_queue' );
        delete_option( 'aisg_bulk_progress' );
        delete_option( 'aisg_cron_trigger_time' );

        wp_send_json_success( array(
            'message' => sprintf( '%d schema eliminati da %d pagine/post.', $count, $count ),
            'count'   => $count,
        ) );
    }

	/**
	 * Render dashboard page
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		include AISG_PATH . 'admin/views/dashboard.php';
	}
}
