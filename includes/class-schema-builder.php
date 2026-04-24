<?php
/**
 * Build JSON-LD schema using AI
 */

namespace IHW_AISG;

class SchemaBuilder {
	/**
	 * Build schema for a post
	 */
	public static function build( $post_id ) {
		try {
			// Step 1: Analyze page
			$page_data = PageAnalyzer::analyze( $post_id );
			if ( ! $page_data ) {
				return null;
			}

			// Step 2: Get llms.txt context
			$llms_context = LlmsFetcher::get_context();

			// Step 3: Get business brief
			$brief = Settings::get( 'business_brief', '' );

			// Step 4: Build system prompt
			$system_prompt = self::build_system_prompt( $llms_context, $brief );

			// Step 5: Build user prompt
			$user_prompt = self::build_user_prompt( $page_data );

			// Step 6: Call AI
			$engine  = AIEngine::make();
			$response = $engine->generate( $user_prompt, $system_prompt );

			// Step 7: Parse and validate response
			$schema_json = self::process_response( $response );

			// Step 8: Save to postmeta
			update_post_meta( $post_id, '_aisg_schema_json', $schema_json );
			update_post_meta( $post_id, '_aisg_schema_generated_at', current_time( 'mysql' ) );

			self::log_success( $post_id, $engine->get_model_name() );

			return $schema_json;
		} catch ( \Exception $e ) {
			self::log_error( $post_id, $e->getMessage() );
			return null;
		}
	}

	/**
	 * Build system prompt
	 */
	private static function build_system_prompt( $llms_context, $brief ) {
		$template = "Sei un esperto di schema markup JSON-LD per SEO e AI visibility.
Genera uno schema markup JSON-LD valido e completo per la pagina descritta.

CONTESTO DEL SITO (llms.txt):
{llms_txt_content}

BRIEF AZIENDALE:
{brief_content}

REGOLE OBBLIGATORIE:
1. Restituisci SOLO un array JSON valido di oggetti schema (senza markdown, senza backtick)
2. Ogni schema deve avere @context, @type, @id unico
3. Se sono presenti FAQ, includi sempre uno schema FAQPage separato
4. Usa SOLO valori reali estratti dal contenuto — mai valori inventati
5. I @id devono usare il permalink reale della pagina
6. Tutti gli URL devono essere assoluti
7. Se un campo non è verificabile, omettilo piuttosto che inventarlo
8. Includi il logo aziendale negli schema Organization e LocalBusiness se disponibile
9. La risposta deve essere un array JSON parsabile senza errori

TIPI DI SCHEMA SUPPORTATI:

[HOSPITALITY]
- Hotel: indirizzo, telefono, email, checkInTime, checkOutTime, priceRange, amenities, room types, images
- BedAndBreakfast: indirizzo, telefono, rooms, amenities, price, images
- Restaurant: indirizzo, telefono, menu, priceRange, cuisine, reviews, images, servesCuisine
- BarOrPub: indirizzo, telefono, openingHours, priceRange, images

[HEALTHCARE & SERVICES]
- MedicalBusiness: indirizzo, telefono, email, specialties, qualifications, orari
- Attorney: indirizzo, telefono, email, specialties, areas of law, education
- Accountant: indirizzo, telefono, email, specialties, areas served

[BUSINESS & COMMERCE]
- LocalBusiness: indirizzo, telefono, email, orari, priceRange, areaServed, image
- Organization: nome, logo, contatto, sito, social profiles, fondazione
- Service: nome, descrizione, provider, price, priceRange, availability
- Product: nome, descrizione, image, price, rating, offers, reviews
- RealEstateAgent: indirizzo, telefono, email, areaServed, properties

[EDUCATION & CULTURE]
- EducationalOrganization: nome, descrizione, indirizzo, contatti, programmi
- TouristAttraction: nome, descrizione, indirizzo, immagini, orari, reviews
- Museum: nome, descrizione, indirizzo, collezioni, orari, admission

[EVENTS & ENTERTAINMENT]
- Event: nome, data, ora, luogo, descrizione, immagine, prezzo
- Movie: nome, descrizione, regista, attori, genere, immagine
- MusicAlbum: nome, artista, data di uscita, brani, immagine

[CONTENT]
- BlogPosting: headline, datePublished, dateModified, author, image, articleBody
- FAQPage: mainEntity array con Question/Answer pairs
- Review: author, reviewRating, reviewBody, datePublished
- WebPage: nome, descrizione, breadcrumb";

		return str_replace(
			array( '{llms_txt_content}', '{brief_content}' ),
			array( $llms_context, $brief ),
			$template
		);
	}

	/**
	 * Get type-specific schema generation instructions
	 */
	private static function get_schema_type_instructions( $schema_type ) {
		$instructions = array(
			'Hotel'                    => 'Estrai: indirizzo fisico, telefono, email, orari check-in/check-out, categorie di camere, servizi, rating, policy di cancellazione, prezzi se disponibili. Includi "@type": "Room" per ogni tipo di camera.',
			'BedAndBreakfast'          => 'Estrai: indirizzo, telefono, email, numero di camere, colazione inclusa, servizi, rating, policy. Includi dettagli su ogni camera disponibile.',
			'Restaurant'               => 'Estrai: indirizzo, telefono, email, tipo di cucina, orari di apertura, menu URL, fascia di prezzo, accettazione prenotazioni, reviews/rating. Includi servesCuisine se identificabile.',
			'BarOrPub'                 => 'Estrai: indirizzo, telefono, email, orari, atmosfera, tipi di bevande, fascia di prezzo, musica/intrattenimento, reviews. Includi specialità se presenti.',
			'MedicalBusiness'          => 'Estrai: indirizzo, telefono, email, specialità mediche, qualifiche del medico, orari di studio, assicurazioni accettate, foto della struttura. Priorità alla credibilità.',
			'Attorney'                 => 'Estrai: indirizzo, telefono, email, aree di diritto, formazione/qualifiche, anni di esperienza, certificazioni, clientele. Includi areaServed se specificata.',
			'Accountant'               => 'Estrai: indirizzo, telefono, email, servizi offerti (contabilità, tasse, consulenza), area servita, qualifiche, esperienza, certificazioni professionali.',
			'LocalBusiness'            => 'Estrai: indirizzo completo, telefono, email, orari di apertura, fascia di prezzo (se applicabile), area servita, reviews/rating. Sempre includi areaServed.',
			'Organization'             => 'Estrai: nome ufficiale, logo, descrizione, indirizzo, telefono, email, profili social, data di fondazione, team/leadership, area di attività.',
			'Service'                  => 'Estrai: nome del servizio, descrizione dettagliata, prezzo/fascia di prezzo, provider, area coperta, disponibilità, rating se presente. Includi serviceType se identificabile.',
			'Product'                  => 'Estrai: nome prodotto, descrizione, immagine, prezzo, valuta, disponibilità (in stock/non in stock), rating, brand, sku se disponibile.',
			'RealEstateAgent'          => 'Estrai: indirizzo, telefono, email, proprietà in vendita/affitto, area specializzazione, licenze, anni di esperienza, rating/reviews.',
			'EducationalOrganization'  => 'Estrai: nome istituto, descrizione, indirizzo, contatti, programmi offerti, accreditamenti, anno fondazione, logo/immagine.',
			'TouristAttraction'        => 'Estrai: nome, descrizione, indirizzo, orari di apertura, ticket price, image, reviews/rating, tipo di attrazione, area geografica.',
			'Event'                    => 'Estrai: nome evento, descrizione, data e ora (formato ISO), luogo (indirizzo o venue), prezzo ticket, URL per biglietti, immagine, organizzatore, capacità.',
			'Movie'                    => 'Estrai: titolo, descrizione/trama, regista, attori principali, data uscita, genere, immagine/poster, durata, rating, lingua.',
			'MusicAlbum'               => 'Estrai: titolo album, artista/musicista, data di uscita, copertina, numero tracce, genere, etichetta discografica, durata totale.',
			'BlogPosting'              => 'Estrai: headline, descrizione/articolo body, autore, datePublished, dateModified, immagine featured, categorie, keyword principali. Includi articleBody completo del contenuto.',
			'FAQPage'                  => 'Estrai le FAQ trovate e formattale come mainEntity array con Question e Answer. Ogni FAQ deve essere una coppia Q/A valida e completa.',
			'Review'                   => 'Estrai: autore review, testo della recensione, rating numerico, data review, prodotto/servizio recensito, positivi/negativi identificati.',
			'WebPage'                  => 'Estrai: breadcrumb se presente, descrizione pagina, keywords principali, data pubblicazione, immagine di copertina. Includi BreadcrumbList se è una sottopagina.',
		);

		$type = $schema_type ?? 'WebPage';
		return isset( $instructions[ $type ] )
			? "ISTRUZIONI PER TIPO '{$type}':\n" . $instructions[ $type ]
			: '';
	}

	/**
	 * Build user prompt
	 */
	private static function build_user_prompt( $page_data ) {
		$faqs_json = ! empty( $page_data['faqs'] ) ? wp_json_encode( $page_data['faqs'] ) : '[]';
		$schema_type = $page_data['schema_type_hint'];

		// Build type-specific instructions
		$type_instructions = self::get_schema_type_instructions( $schema_type );

		$prompt = "Genera gli schema JSON-LD per questa pagina:

Tipo riconosciuto: {schema_type_hint}
Titolo: {title}
URL: {permalink}
Tipo post: {post_type}
Descrizione meta: {meta_desc}
Contenuto (estratto): {content_clean}
FAQ trovate: {faqs_json}
Data pubblicazione: {date_published}
Data modifica: {date_modified}
Immagine featured: {featured_image}
Logo aziendale: {logo_url}

{type_instructions}

Genera gli schema JSON-LD appropriati come array JSON.";

		return str_replace(
			array(
				'{schema_type_hint}',
				'{title}',
				'{permalink}',
				'{post_type}',
				'{meta_desc}',
				'{content_clean}',
				'{faqs_json}',
				'{date_published}',
				'{date_modified}',
				'{featured_image}',
				'{logo_url}',
				'{type_instructions}',
			),
			array(
				$page_data['schema_type_hint'],
				$page_data['title'],
				$page_data['permalink'],
				$page_data['post_type'],
				$page_data['meta_desc'],
				$page_data['content_clean'],
				$faqs_json,
				$page_data['date_published'],
				$page_data['date_modified'],
				$page_data['featured_image'],
				$page_data['logo_url'],
				$type_instructions,
			),
			$prompt
		);
	}

	/**
	 * Process AI response and validate
	 */
	private static function process_response( $response ) {
		// Strip markdown fencing if present
		$response = preg_replace( '/^```(?:json)?\n?|\n?```$/m', '', $response );
		$response = trim( $response );

		// Parse JSON
		$schemas = json_decode( $response, true );
		if ( null === $schemas ) {
			throw new \Exception( 'Invalid JSON response from AI: ' . substr( $response, 0, 100 ) );
		}

		// Ensure it's an array of schemas
		if ( isset( $schemas['@context'] ) || isset( $schemas['@type'] ) ) {
			$schemas = array( $schemas );
		}

		if ( ! is_array( $schemas ) ) {
			throw new \Exception( 'AI response must be JSON array' );
		}

		// Validate each schema
		$validated = array();
		foreach ( $schemas as $schema ) {
			if ( ! is_array( $schema ) ) {
				continue;
			}

			// Required fields
			if ( empty( $schema['@context'] ) || empty( $schema['@type'] ) ) {
				continue;
			}

			// Sanitize URLs
			if ( ! empty( $schema['url'] ) ) {
				$schema['url'] = esc_url_raw( $schema['url'] );
			}

			// Sanitize strings
			foreach ( $schema as &$value ) {
				if ( is_string( $value ) && false === strpos( $value, 'http' ) ) {
					$value = sanitize_text_field( $value );
				}
			}

			$validated[] = $schema;
		}

		if ( empty( $validated ) ) {
			throw new \Exception( 'No valid schemas in AI response' );
		}

		// Return as pretty-printed JSON
		return wp_json_encode( $validated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	/**
	 * Log success
	 */
	private static function log_success( $post_id, $model ) {
		$log = get_option( 'aisg_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'post_id'   => $post_id,
			'post_title' => get_the_title( $post_id ),
			'time'      => current_time( 'mysql' ),
			'status'    => 'success',
			'model'     => $model,
		);

		// Keep only last 1000 entries
		if ( count( $log ) > 1000 ) {
			$log = array_slice( $log, -1000 );
		}

		update_option( 'aisg_log', $log );
	}

	/**
	 * Log error
	 */
	private static function log_error( $post_id, $error ) {
		$log = get_option( 'aisg_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'post_id'   => $post_id,
			'post_title' => get_the_title( $post_id ),
			'time'      => current_time( 'mysql' ),
			'status'    => 'error',
			'message'   => substr( $error, 0, 200 ),
		);

		// Keep only last 1000 entries
		if ( count( $log ) > 1000 ) {
			$log = array_slice( $log, -1000 );
		}

		update_option( 'aisg_log', $log );
	}
}
