<?php
/**
 * Dashboard view
 */

use IHW_AISG\Settings;
use IHW_AISG\BulkProcessor;
use IHW_AISG\SchemaConflictResolver;
use IHW_AISG\Diagnostics;

$post_types = Settings::get( 'post_types', array( 'post', 'page' ) );

// Count posts
$total_query = new WP_Query( array(
	'post_type'      => $post_types,
	'posts_per_page' => -1,
	'post_status'    => 'publish',
	'fields'         => 'ids',
) );
$total_count = $total_query->found_posts;

// Count with schema
$with_schema_query = new WP_Query( array(
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
) );
$with_schema_count = $with_schema_query->found_posts;

$percentage = $total_count > 0 ? round( ( $with_schema_count / $total_count ) * 100 ) : 0;

$progress = BulkProcessor::get_progress();
$log = get_option( 'aisg_log', array() );

?>
<div class="wrap">
	<h1>AI Schema Generator - Dashboard</h1>

	<!-- Coverage Cards -->
	<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
		<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="font-size: 32px; font-weight: bold; color: #0073aa;">
				<?php echo intval( $total_count ); ?>
			</div>
			<div style="color: #666; margin-top: 8px;">Pagine/Post</div>
		</div>

		<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="font-size: 32px; font-weight: bold; color: #28a745;">
				<?php echo intval( $with_schema_count ); ?>
			</div>
			<div style="color: #666; margin-top: 8px;">Schema Generati (<?php echo intval( $percentage ); ?>%)</div>
		</div>

		<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<div style="font-size: 32px; font-weight: bold; color: #ffc107;">
				<?php echo intval( $total_count - $with_schema_count ); ?>
			</div>
			<div style="color: #666; margin-top: 8px;">Mancanti</div>
		</div>
	</div>

	<!-- Progress Bar -->
	<?php if ( ! empty( $progress ) && 'running' === ( $progress['status'] ?? '' ) ) : ?>
		<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
			<h3>Generazione in Corso</h3>

			<?php
			$method = $progress['method'] ?? 'ajax';
			$method_label = 'wp-cron' === $method ? 'WP-Cron' : 'AJAX Polling';
			$method_color = 'wp-cron' === $method ? '#28a745' : '#0073aa';
			?>
			<p style="margin: 0 0 12px 0; font-size: 12px; color: #666;">
				Metodo: <span style="color: <?php echo $method_color; ?>; font-weight: bold;">● <?php echo $method_label; ?></span>
			</p>

			<div style="margin: 12px 0;">
				<div style="background: #e9ecef; height: 24px; border-radius: 4px; overflow: hidden;">
					<?php
					$done = intval( $progress['done'] ?? 0 );
					$total = intval( $progress['total'] ?? 1 );
					$pct = round( ( $done / max( $total, 1 ) ) * 100 );
					?>
					<div style="background: #0073aa; height: 100%; width: <?php echo intval( $pct ); ?>%; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold;">
						<?php echo intval( $pct ); ?>%
					</div>
				</div>
			</div>
			<p style="margin: 8px 0; font-size: 14px;">
				<?php echo intval( $done ); ?> / <?php echo intval( $total ); ?> completati
				<?php if ( ! empty( $progress['errors'] ) ) : ?>
					(<?php echo intval( $progress['errors'] ); ?> errori)
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Detected Conflicts & Diagnostic Info -->
	<?php
	$conflicts_summary = SchemaConflictResolver::get_summary();

	// Check for active competing plugins
	$competing_plugins = array(
		'wordpress-seo/wp-seo.php' => 'Yoast SEO',
		'seo-by-rank-math/rank-math.php' => 'RankMath',
		'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All-in-One SEO',
		'seopress/seopress.php' => 'SEOPress',
		'autodescription/autodescription.php' => 'The SEO Framework',
	);

	$active_competitors = array();
	foreach ( $competing_plugins as $plugin_file => $plugin_name ) {
		if ( is_plugin_active( $plugin_file ) ) {
			$active_competitors[ $plugin_file ] = $plugin_name;
		}
	}

	// Show conflicts if they exist
	if ( ! empty( $conflicts_summary ) ) :
		?>
		<div style="background: #d4edda; padding: 20px; border-radius: 8px; border-left: 4px solid #28a745; margin-bottom: 20px;">
			<h3 style="color: #155724; margin-top: 0;">✓ Schema Conflicts Risolti</h3>
			<p style="color: #155724; margin-bottom: 12px;">Il seguente plugin/tema stava iniettando schema markup. Sono stati disattivati automaticamente:</p>
			<ul style="margin: 0; padding-left: 20px;">
				<?php foreach ( $conflicts_summary as $plugin => $count ) : ?>
					<li style="color: #155724;">
						<strong><?php echo esc_html( ucfirst( $plugin ) ); ?></strong> -
						<?php echo intval( $count ); ?> hook<?php echo $count > 1 ? 's' : ''; ?> disattivato<?php echo $count > 1 ? 'i' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php elseif ( ! empty( $active_competitors ) ) : ?>
		<div style="background: #fff3cd; padding: 20px; border-radius: 8px; border-left: 4px solid #ffc107; margin-bottom: 20px;">
			<h3 style="color: #856404; margin-top: 0;">⚠️ Plugin Competitor Rilevati</h3>
			<p style="color: #856404; margin-bottom: 12px;">I seguenti plugin SEO che producono schema sono attivi. Se vedi schema duplicati, potrebbe essere necessario disattivare questi plugin:</p>
			<ul style="margin: 0; padding-left: 20px;">
				<?php foreach ( $active_competitors as $plugin_file => $plugin_name ) : ?>
					<li style="color: #856404;">
						<strong><?php echo esc_html( $plugin_name ); ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>
			<p style="margin-top: 12px; margin-bottom: 0; font-size: 12px; color: #856404;">
				💡 Suggerimento: Se vedi schema duplicati nelle Pagine Ricche Risultati di Google, disattiva questi plugin o il loro generatore di schema.
			</p>
		</div>
	<?php endif; ?>

	<!-- Bulk Actions -->
	<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
		<h3>Azioni Bulk</h3>
        <div style="display: flex; gap: 10px;">
            <button class="button button-primary" id="aisg-generate-missing">
                Genera Mancanti
            </button>
            <button class="button" id="aisg-generate-outdated">
                Rigenera Non Aggiornati
            </button>
            <button class="button" id="aisg-generate-all">
                Genera Tutto
            </button>
        </div>
        <div style="margin-top: 10px;">
            <button class="button" id="aisg-clear-all-schemas" style="background: #dc3545; color: white; border-color: #c82333;">
                🔥 PULISCI TUTTI GLI SCHEMA (DEBUG)
            </button>
        </div>
	</div>

	<!-- Recent Log -->
	<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
		<h3>Log Recenti</h3>
		<table style="width: 100%; border-collapse: collapse;">
			<thead>
				<tr style="border-bottom: 2px solid #ddd;">
					<th style="text-align: left; padding: 8px;">Timestamp</th>
					<th style="text-align: left; padding: 8px;">Post</th>
					<th style="text-align: left; padding: 8px;">Status</th>
					<th style="text-align: left; padding: 8px;">Dettagli</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$displayed = 0;
				foreach ( array_reverse( $log ) as $entry ) {
					if ( $displayed >= 20 ) {
						break;
					}
					$displayed++;
					$status_color = 'success' === ( $entry['status'] ?? '' ) ? '#28a745' : '#dc3545';
					$status_text = 'success' === ( $entry['status'] ?? '' ) ? '✓' : '✗';
					?>
					<tr style="border-bottom: 1px solid #eee;">
						<td style="padding: 8px; font-size: 12px;">
							<?php echo esc_html( $entry['time'] ?? '' ); ?>
						</td>
						<td style="padding: 8px; font-size: 12px;">
							<?php echo esc_html( $entry['post_title'] ?? 'Unknown' ); ?>
						</td>
						<td style="padding: 8px; font-size: 12px; color: <?php echo esc_attr( $status_color ); ?>; font-weight: bold;">
							<?php echo esc_html( $status_text ); ?>
						</td>
						<td style="padding: 8px; font-size: 12px;">
							<?php
							if ( 'success' === ( $entry['status'] ?? '' ) ) {
								echo 'Model: ' . esc_html( $entry['model'] ?? '' );
							} else {
								echo esc_html( $entry['message'] ?? '' );
							}
							?>
						</td>
					</tr>
				<?php } ?>
			</tbody>
		</table>
		<?php if ( empty( $log ) ) : ?>
			<p style="padding: 12px; color: #666;">Nessun log ancora.</p>
		<?php endif; ?>
	</div>
</div>

<script>
	function updateProgressBar(progress) {
		let progressContainer = document.querySelector('.aisg-progress-container');

		if (!progressContainer) {
			const bulkActionsDiv = document.querySelector('div:has(> #aisg-generate-missing)').parentNode.parentNode;
			if (bulkActionsDiv) {
				progressContainer = document.createElement('div');
				progressContainer.className = 'aisg-progress-container';
				progressContainer.style.background = 'white';
				progressContainer.style.padding = '20px';
				progressContainer.style.borderRadius = '8px';
				progressContainer.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
				progressContainer.style.marginBottom = '20px';

				bulkActionsDiv.parentNode.insertBefore(progressContainer, bulkActionsDiv.nextSibling);
			}
		}

		if (progressContainer) {
			const method = progress.method || 'ajax';
			const methodLabel = 'wp-cron' === method ? 'WP-Cron' : 'AJAX Polling';
			const methodColor = 'wp-cron' === method ? '#28a745' : '#0073aa';

			const done = progress.done || 0;
			const total = progress.total || 1;
			const pct = Math.round((done / Math.max(total, 1)) * 100);

			progressContainer.innerHTML = `
				<h3>Generazione in Corso</h3>
				<p style="margin: 0 0 12px 0; font-size: 12px; color: #666;">
					Metodo: <span style="color: ${methodColor}; font-weight: bold;">● ${methodLabel}</span>
				</p>
				<div style="margin: 12px 0;">
					<div style="background: #e9ecef; height: 24px; border-radius: 4px; overflow: hidden;">
						<div style="background: #0073aa; height: 100%; width: ${pct}%; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold;">
							${pct}%
						</div>
					</div>
				</div>
				<p style="margin: 8px 0; font-size: 14px;">
					${done} / ${total} completati
					${progress.errors ? `(${progress.errors} errori)` : ''}
				</p>
			`;
		}
	}

	function processBatch() {
		fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams({
				action: 'aisg_process_batch',
				nonce: '<?php echo esc_attr( wp_create_nonce( 'aisg_process_batch' ) ); ?>'
			})
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				updateProgressBar(data.data);

				if (data.data?.status === 'complete') {
					location.reload();
				} else if (data.data?.has_more) {
					setTimeout(processBatch, 500);
				}
			} else {
				console.error('Error processing batch:', data);
			}
		})
		.catch(err => {
			console.error('Network error:', err);
			setTimeout(processBatch, 1000);
		});
	}

	function startProgressPolling() {
		if (window.progressPollInterval) {
			clearInterval(window.progressPollInterval);
		}

		window.progressPollInterval = setInterval(() => {
			fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'aisg_get_progress',
					nonce: '<?php echo esc_attr( wp_create_nonce( 'aisg_get_progress' ) ); ?>'
				})
			})
			.then(response => response.json())
			.then(data => {
				if (data.success && data.data) {
					updateProgressBar(data.data);

					if (data.data.status === 'complete') {
						clearInterval(window.progressPollInterval);
						location.reload();
					}
				}
			})
			.catch(err => {
				console.error('Polling error:', err);
			});
		}, 2000);
	}

	function bulkGenerate(mode) {
		const button = event.target;
		button.disabled = true;
		button.textContent = 'Preparando...';

		fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams({
				action: 'aisg_bulk_generate',
				mode: mode,
				nonce: '<?php echo esc_attr( wp_create_nonce( 'aisg_bulk_generate' ) ); ?>'
			})
		})
		.then(response => response.json())
		.then(data => {
			button.disabled = false;
			if (data.success) {
				button.textContent = 'Generando...';

				const initialProgress = {
					total: data.data.count,
					done: 0,
					errors: 0,
					status: 'running',
					method: 'ajax'
				};
				updateProgressBar(initialProgress);

				startProgressPolling();
				processBatch();
			} else {
				button.textContent = mode === 'missing' ? 'Genera Mancanti' : (mode === 'outdated' ? 'Rigenera Non Aggiornati' : 'Genera Tutto');
				alert('Errore: ' + (data.data?.message || 'Riprova'));
			}
		})
		.catch(err => {
			alert('Errore di rete: ' + err);
			button.disabled = false;
			button.textContent = mode === 'missing' ? 'Genera Mancanti' : (mode === 'outdated' ? 'Rigenera Non Aggiornati' : 'Genera Tutto');
		});
	}

	function bindBulkActions() {
		document.getElementById('aisg-generate-missing')?.addEventListener('click', () => bulkGenerate('missing'));
		document.getElementById('aisg-generate-outdated')?.addEventListener('click', () => bulkGenerate('outdated'));
		document.getElementById('aisg-generate-all')?.addEventListener('click', () => bulkGenerate('all'));
	}

	function bindClearAllButton() {
		document.getElementById('aisg-clear-all-schemas')?.addEventListener('click', function(e) {
			e.preventDefault();

			if (!confirm('⚠️ ATTENZIONE: Questo eliminerà TUTTI gli schema generati da TUTTE le pagine/post. Vuoi davvero continuare?')) {
				return;
			}

			const button = this;
			button.disabled = true;
			button.textContent = 'Pulizia in corso...';

			fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'aisg_clear_all_schemas',
					nonce: '<?php echo esc_attr( wp_create_nonce( 'aisg_clear_all_schemas' ) ); ?>'
				})
			})
			.then(response => response.json())
			.then(data => {
				button.disabled = false;
				button.textContent = '🔥 PULISCI TUTTI GLI SCHEMA (DEBUG)';

				if (data.success) {
					alert('✓ Tutti gli schema sono stati eliminati. ' + data.data.message);
					location.reload();
				} else {
					alert('✗ Errore durante la pulizia: ' + (data.data?.message || 'Riprova'));
				}
			})
			.catch(err => {
				alert('✗ Errore di rete: ' + err);
				button.disabled = false;
				button.textContent = '🔥 PULISCI TUTTI GLI SCHEMA (DEBUG)';
			});
		});
	}

	bindBulkActions();
	bindClearAllButton();

	window.addEventListener('beforeunload', () => {
		if (window.progressPollInterval) {
			clearInterval(window.progressPollInterval);
		}
	});
</script>

	<!-- Debug Panel -->
	<?php Diagnostics::output_debug_panel(); ?>
