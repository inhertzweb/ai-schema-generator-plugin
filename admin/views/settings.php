<?php
/**
 * Settings page view
 */

use IHW_AISG\Settings;
use IHW_AISG\AIEngine;
use IHW_AISG\SchemaConflictResolver;

$provider = Settings::get( 'provider', 'claude' );
$api_key_claude = Settings::get_api_key( 'claude' );
$api_key_gemini = Settings::get_api_key( 'gemini' );
$model_claude = Settings::get( 'model_claude', 'claude-sonnet-4-20250514' );
$model_gemini = Settings::get( 'model_gemini', 'gemini-2.5-flash' );
$brief = Settings::get( 'business_brief', '' );
$llms_url = Settings::get( 'llms_txt_url', home_url( '/llms.txt' ) );
$refresh_freq = Settings::get( 'llms_refresh_frequency', '24h' );
$post_types = Settings::get( 'post_types', array( 'post', 'page' ) );
$auto_gen = Settings::get( 'auto_generate_on_publish', false );
$tiers = Settings::get( 'schema_tiers', array( 'tier1' ) );
$language = Settings::get( 'output_language', 'auto' );
$logo_url = Settings::get_logo_url();
$custom_logo_url = Settings::get( 'logo_url', '' );

?>
<div class="wrap">
	<h1>AI Schema Generator - Impostazioni</h1>

	<?php
	$test_result = null;
	if ( isset( $_POST['aisg_test_connection'] ) ) {
		check_admin_referer( 'aisg_settings' );
		$test_result = AIEngine::test_connection();
	}

	if ( $test_result === true ) {
		echo '<div class="notice notice-success"><p>✓ Connessione riuscita!</p></div>';
	} elseif ( $test_result === false ) {
		echo '<div class="notice notice-error"><p>✗ Connessione fallita. Controlla la chiave API.</p></div>';
	}
	?>

	<form method="post" action="options.php">
		<?php settings_fields( 'aisg_settings' ); ?>

		<table class="aisg-settings-table">
			<tr>
				<th colspan="2">Provider AI</th>
			</tr>
			<tr>
				<td><label>Provider</label></td>
				<td>
					<label>
						<input type="radio" name="aisg_provider" value="claude" <?php checked( $provider, 'claude' ); ?>> Claude (Anthropic)
					</label>
					<br>
					<label>
						<input type="radio" name="aisg_provider" value="gemini" <?php checked( $provider, 'gemini' ); ?>> Gemini (Google)
					</label>
				</td>
			</tr>
			<tr>
				<td><label for="aisg_model_claude">Modello Claude</label></td>
				<td>
					<select id="aisg_model_claude" name="aisg_model_claude">
						<option value="claude-sonnet-4-20250514" <?php selected( $model_claude, 'claude-sonnet-4-20250514' ); ?>>Claude Sonnet 4 (Consigliato)</option>
						<option value="claude-opus-4-1" <?php selected( $model_claude, 'claude-opus-4-1' ); ?>>Claude Opus 4</option>
					</select>
				</td>
			</tr>
			<tr>
				<td><label for="aisg_api_key_claude">API Key Claude</label></td>
				<td>
					<input type="password" id="aisg_api_key_claude" name="aisg_api_key_claude" value="<?php echo esc_attr( $api_key_claude ? '••••••••' : '' ); ?>" placeholder="sk-...">
					<div class="aisg-hint">La chiave viene cifrata e salvata in modo sicuro</div>
				</td>
			</tr>
			<tr>
				<td><label for="aisg_model_gemini">Modello Gemini</label></td>
				<td>
					<select id="aisg_model_gemini" name="aisg_model_gemini">
						<?php
						try {
							if ( ! class_exists( '\IHW_AISG\GeminiModels' ) ) {
								echo '<option value="">⚠️ Classe GeminiModels non trovata</option>';
							} else {
								$gemini_models = \IHW_AISG\GeminiModels::get_models();
								if ( empty( $gemini_models ) ) {
									echo '<option value="">⚠️ Nessun modello disponibile</option>';
								} else {
									foreach ( $gemini_models as $model_id => $model_name ) {
										echo '<option value="' . esc_attr( $model_id ) . '" ' . selected( $model_gemini, $model_id ) . '>' . esc_html( $model_name ) . '</option>';
									}
								}
							}
						} catch ( Exception $e ) {
							echo '<option value="">⚠️ Errore: ' . esc_html( $e->getMessage() ) . '</option>';
						}
						?>
					</select>
					<div class="aisg-hint">Modelli caricati da Google Generative AI API</div>
				</td>
			</tr>
			<tr>
				<td><label for="aisg_api_key_gemini">API Key Gemini</label></td>
				<td>
					<input type="password" id="aisg_api_key_gemini" name="aisg_api_key_gemini" value="<?php echo esc_attr( $api_key_gemini ? '••••••••' : '' ); ?>" placeholder="AIza...">
					<div class="aisg-hint">La chiave viene cifrata e salvata in modo sicuro</div>
				</td>
			</tr>

			<tr>
				<th colspan="2">Contesto Sito</th>
			</tr>
			<tr>
				<td><label for="aisg_business_brief">Brief Aziendale</label></td>
				<td>
					<textarea id="aisg_business_brief" name="aisg_business_brief" placeholder="Descrivi il tuo business, i servizi, la localizzazione, i clienti target, le certificazioni...">
<?php echo esc_textarea( $brief ); ?>
					</textarea>
					<div class="aisg-hint">Almeno 200 caratteri. Fornisce contesto all'AI per generare schema appropriati</div>
				</td>
			</tr>
			<tr>
				<td><label for="aisg_llms_txt_url">URL llms.txt</label></td>
				<td>
					<input type="text" id="aisg_llms_txt_url" name="aisg_llms_txt_url" value="<?php echo esc_attr( $llms_url ); ?>">
					<div class="aisg-hint">Posizione del file llms.txt (se disponibile)</div>
				</td>
			</tr>
			<tr>
				<td><label for="aisg_llms_refresh_frequency">Frequenza Refresh llms.txt</label></td>
				<td>
					<select id="aisg_llms_refresh_frequency" name="aisg_llms_refresh_frequency">
						<option value="24h" <?php selected( $refresh_freq, '24h' ); ?>>Ogni 24 ore</option>
						<option value="7d" <?php selected( $refresh_freq, '7d' ); ?>>Ogni 7 giorni</option>
						<option value="manual" <?php selected( $refresh_freq, 'manual' ); ?>>Manuale</option>
					</select>
				</td>
			</tr>

			<tr>
				<th colspan="2">Generazione Schema</th>
			</tr>
			<tr>
				<td><label>Tipi di Post</label></td>
				<td>
					<label><input type="checkbox" name="aisg_post_types[]" value="post" <?php checked( in_array( 'post', $post_types ) ); ?>> Post</label><br>
					<label><input type="checkbox" name="aisg_post_types[]" value="page" <?php checked( in_array( 'page', $post_types ) ); ?>> Pagine</label><br>
					<label><input type="checkbox" name="aisg_post_types[]" value="case_study" <?php checked( in_array( 'case_study', $post_types ) ); ?>> Case Study</label>
				</td>
			</tr>
			<tr>
				<td><label><input type="checkbox" name="aisg_auto_generate_on_publish" value="1" <?php checked( $auto_gen, 1 ); ?>> Auto-genera al publish</label></td>
				<td>
					<div class="aisg-hint">Genera automaticamente lo schema quando si pubblica un nuovo post</div>
				</td>
			</tr>
			<tr>
				<td><label>Schema Tiers</label></td>
				<td>
					<label><input type="checkbox" name="aisg_schema_tiers[]" value="tier1" <?php checked( in_array( 'tier1', $tiers ) ); ?>> Tier 1 (Critical)</label><br>
					<label><input type="checkbox" name="aisg_schema_tiers[]" value="tier2" <?php checked( in_array( 'tier2', $tiers ) ); ?>> Tier 2 (High)</label><br>
					<label><input type="checkbox" name="aisg_schema_tiers[]" value="tier3" <?php checked( in_array( 'tier3', $tiers ) ); ?>> Tier 3 (Medium)</label>
				</td>
			</tr>
			<tr>
				<td><label for="aisg_output_language">Lingua Output</label></td>
				<td>
					<select id="aisg_output_language" name="aisg_output_language">
						<option value="auto" <?php selected( $language, 'auto' ); ?>>Auto-rilevamento</option>
						<option value="it" <?php selected( $language, 'it' ); ?>>Italiano</option>
						<option value="en" <?php selected( $language, 'en' ); ?>>English</option>
					</select>
				</td>
			</tr>

			<tr>
				<th colspan="2">Schema - Logo</th>
			</tr>
			<tr>
				<td><label for="aisg_logo_url">Logo per Schema (Fallback)</label></td>
				<td>
					<?php if ( $logo_url ) : ?>
						<div style="margin-bottom: 12px;">
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="Logo" style="max-height: 80px; max-width: 200px; display: block;">
							<p style="font-size: 12px; color: #666; margin: 4px 0;">
								<?php if ( get_theme_mod( 'custom_logo' ) ) : ?>
									✓ Usa il logo dal customizer del tema
								<?php else : ?>
									✓ Usa il logo salvato nelle impostazioni plugin
								<?php endif; ?>
							</p>
						</div>
					<?php endif; ?>
					<input type="url" id="aisg_logo_url" name="aisg_logo_url" value="<?php echo esc_attr( $custom_logo_url ); ?>" placeholder="https://...">
					<div class="aisg-hint">Se il tema non ha un logo nel customizer, questo sarà usato negli schema LocalBusiness e Organization</div>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<div style="margin-top: 20px; padding: 12px; background: #f5f5f5; border-radius: 4px;">
		<p><strong>Test Connessione:</strong></p>
		<form method="post">
			<?php wp_nonce_field( 'aisg_settings' ); ?>
			<button type="submit" name="aisg_test_connection" class="button">
				Test Connessione API
			</button>
		</form>
	</div>

	<?php
	$conflicts = \IHW_AISG\SchemaConflictResolver::get_disabled_conflicts();
	if ( ! empty( $conflicts ) ) :
		?>
		<div style="margin-top: 20px; padding: 12px; background: #d1ecf1; border-left: 4px solid #17a2b8; border-radius: 4px;">
			<p><strong style="color: #0c5460;">ℹ️ Schema Conflicts Risolti</strong></p>
			<p style="color: #0c5460; margin: 8px 0; font-size: 13px;">
				<?php echo count( $conflicts ); ?> hook<?php echo count( $conflicts ) > 1 ? 's' : ''; ?> da altri plugin/tema
				è stato<?php echo count( $conflicts ) > 1 ? 'no' : ''; ?> disattivato<?php echo count( $conflicts ) > 1 ? 'i' : ''; ?> automaticamente
				per evitare duplicati.
			</p>
			<details style="margin-top: 8px;">
				<summary style="cursor: pointer; color: #0c5460; font-weight: bold;">
					Visualizza dettagli
				</summary>
				<div style="margin-top: 8px; padding: 8px; background: rgba(255,255,255,0.5); border-radius: 3px; font-size: 12px;">
					<?php foreach ( $conflicts as $i => $conflict ) : ?>
						<div style="margin-bottom: 4px;">
							<?php echo ( $i + 1 ) . '. ' . esc_html( $conflict['plugin'] ) . ' → ' . esc_html( $conflict['function'] ) . ' (' . intval( $conflict['priority'] ) . ')'; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</details>
		</div>
	<?php endif; ?>
</div>
