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

REGOLA AGGIUNTIVA PER FAQ:
- Se generi schema FAQPage, RIMUOVI COMPLETAMENTE ogni tag HTML dalle risposte (answer)
- Le risposte devono essere testo puro, senza <p>, <br>, <strong>, <em>, etc.
- Converti tag HTML in testo semplice (es: <strong>testo</strong> → testo)

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
			'Organization'             => 'Estrai SOLO: nome ufficiale (non abbreviare), un UNICO logo, indirizzo completo usando streetAddress, addressLocality, postalCode, addressCountry, addressRegion (usa i DATI INDIRIZZO forniti sopra se disponibili), telefono, email, sito URL, profili social, descrizione. Non includere campi duplicati (es. non mettere sia "logo" che "image"). Mappa i dati indirizzo forniti alle proprietà corrette dello schema.',
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
			'WebPage'                  => 'Estrai: breadcrumb se presente, descrizione pagina, keywords principali, data pubblicazione, immagine di copertina. Se è una sottopagina, includi BreadcrumbList con itemListElement array. IMPORTANTE: ogni ListItem DEVE avere campi position (numero), name (testo), item (URL). Ometti item solo se l\'URL non è disponibile nel contenuto.',
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

		// Extract address data from footer and Yoast SEO
		$address_data = self::extract_address_data();
		$address_context = ! empty( $address_data ) ? "DATI INDIRIZZO (dal footer/Yoast SEO):\n" . $address_data . "\n\n" : '';

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

{address_context}{type_instructions}

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
				'{address_context}',
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
				$address_context,
				$type_instructions,
			),
			$prompt
		);
	}

	/**
	 * Extract address data from Yoast SEO first, fallback to footer HTML parsing (via AI)
	 */
	private static function extract_address_data() {
		// First, try Yoast SEO settings
		$address_from_yoast = self::extract_address_from_yoast();
		if ( ! empty( $address_from_yoast ) ) {
			return $address_from_yoast;
		}

		// Fallback: try to extract from footer HTML using AI
		$footer_html = self::get_footer_html();
		if ( ! empty( $footer_html ) ) {
			$address_from_footer = self::extract_address_from_footer_html( $footer_html );
			if ( ! empty( $address_from_footer ) ) {
				return $address_from_footer;
			}
		}

		return '';
	}

	/**
	 * Get footer HTML from the site
	 */
	private static function get_footer_html() {
		try {
			$response = wp_remote_get( home_url( '/' ), array(
				'timeout'     => 10,
				'sslverify'   => false,
				'user-agent'  => 'Mozilla/5.0 (AI Schema Generator)',
			) );

			if ( is_wp_error( $response ) ) {
				return '';
			}

			$html = wp_remote_retrieve_body( $response );
			if ( empty( $html ) ) {
				return '';
			}

			// Extract footer tag
			if ( preg_match( '/<footer[^>]*>(.+?)<\/footer>/is', $html, $matches ) ) {
				return $matches[1];
			}

			return '';
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Use AI to extract address from footer HTML
	 */
	private static function extract_address_from_footer_html( $footer_html ) {
		try {
			// Prepare prompt for AI
			$prompt = "Analizza questo HTML del footer e estrai SOLO i dati di indirizzo/contatti dell'azienda in formato strutturato.\n\n";
			$prompt .= "HTML Footer:\n" . $footer_html . "\n\n";
			$prompt .= "Estrai e ritorna SOLO:\n";
			$prompt .= "- Indirizzo (via, numero)\n";
			$prompt .= "- CAP/Codice Postale\n";
			$prompt .= "- Città\n";
			$prompt .= "- Provincia/Regione\n";
			$prompt .= "- Nazione\n";
			$prompt .= "- Telefono\n";
			$prompt .= "- Email\n\n";
			$prompt .= "Formato risposta (una riga per campo, ometti se non trovato):\n";
			$prompt .= "streetAddress: [valore]\n";
			$prompt .= "postalCode: [valore]\n";
			$prompt .= "addressLocality: [valore]\n";
			$prompt .= "addressRegion: [valore]\n";
			$prompt .= "addressCountry: [valore]\n";
			$prompt .= "telephone: [valore]\n";
			$prompt .= "email: [valore]";

			// Call AI
			$engine = AIEngine::make();
			$response = $engine->generate( $prompt, "Sei un parser HTML specializzato nell'estrazione di dati di contatto da footer." );

			if ( empty( $response ) ) {
				return '';
			}

			// Parse response
			$lines = explode( "\n", $response );
			$address_data = array();

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) || strpos( $line, ':' ) === false ) {
					continue;
				}

				list( $key, $value ) = explode( ':', $line, 2 );
				$key = trim( $key );
				$value = trim( $value );

				if ( ! empty( $value ) && $value !== '[valore]' ) {
					$address_data[ $key ] = $value;
				}
			}

			if ( empty( $address_data ) ) {
				return '';
			}

			// Format for schema
			$formatted = array();
			$label_map = array(
				'streetAddress'     => 'Via/Indirizzo',
				'postalCode'        => 'CAP',
				'addressLocality'   => 'Città',
				'addressRegion'     => 'Provincia/Regione',
				'addressCountry'    => 'Nazione',
				'telephone'         => 'Telefono',
				'email'             => 'Email',
			);

			foreach ( $address_data as $key => $value ) {
				$label = isset( $label_map[ $key ] ) ? $label_map[ $key ] : $key;
				$formatted[] = $label . ': ' . sanitize_text_field( $value );
			}

			return ! empty( $formatted ) ? implode( "\n", $formatted ) : '';
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Extract address from Yoast SEO settings (fallback)
	 */
	private static function extract_address_from_yoast() {
		$address_parts = array();

		$yoast_address = get_option( 'wpseo_local_address' );
		$yoast_city = get_option( 'wpseo_local_city' );
		$yoast_state = get_option( 'wpseo_local_state' );
		$yoast_zipcode = get_option( 'wpseo_local_zipcode' );
		$yoast_country = get_option( 'wpseo_local_country' );
		$yoast_phone = get_option( 'wpseo_local_phone' );
		$yoast_email = get_option( 'wpseo_local_business_email' );

		if ( ! empty( $yoast_address ) ) {
			$address_parts[] = 'Via/Indirizzo: ' . sanitize_text_field( $yoast_address );
		}
		if ( ! empty( $yoast_zipcode ) ) {
			$address_parts[] = 'CAP: ' . sanitize_text_field( $yoast_zipcode );
		}
		if ( ! empty( $yoast_city ) ) {
			$address_parts[] = 'Città: ' . sanitize_text_field( $yoast_city );
		}
		if ( ! empty( $yoast_state ) ) {
			$address_parts[] = 'Provincia/Regione: ' . sanitize_text_field( $yoast_state );
		}
		if ( ! empty( $yoast_country ) ) {
			$address_parts[] = 'Nazione: ' . sanitize_text_field( $yoast_country );
		}
		if ( ! empty( $yoast_phone ) ) {
			$address_parts[] = 'Telefono: ' . sanitize_text_field( $yoast_phone );
		}
		if ( ! empty( $yoast_email ) ) {
			$address_parts[] = 'Email: ' . sanitize_email( $yoast_email );
		}

		return ! empty( $address_parts ) ? implode( "\n", $address_parts ) : '';
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

			// Fix address fields for all schema types
			$schema = self::fix_address_fields( $schema );

			// Fix BreadcrumbList if present (add missing 'item' field)
			if ( 'BreadcrumbList' === $schema['@type'] && ! empty( $schema['itemListElement'] ) ) {
				$schema = self::fix_breadcrumb_list( $schema );
			}

			// Fix Organization if present (remove duplicates)
			if ( 'Organization' === $schema['@type'] ) {
				$schema = self::fix_organization( $schema );
			}

			// Fix LocalBusiness if present (add missing telephone)
			if ( 'LocalBusiness' === $schema['@type'] ) {
				$schema = self::fix_local_business( $schema );
			}

			// Fix FAQPage if present (remove HTML from answers)
			if ( 'FAQPage' === $schema['@type'] ) {
				$schema = self::fix_faq_page( $schema );
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
	 * Fix address fields in all schema types: deduplicate and clean up
	 */
	private static function fix_address_fields( $schema ) {
		// Handle nested 'address' field in any schema type
		if ( ! empty( $schema['address'] ) ) {
			// If address is an array of arrays, keep only the first one (deduplicate)
			if ( is_array( $schema['address'] ) ) {
				if ( is_array( reset( $schema['address'] ) ) ) {
					// It's an array of address objects
					$schema['address'] = $schema['address'][0];
				}
			}

			// If address is now an array (PostalAddress object), validate it
			if ( is_array( $schema['address'] ) ) {
				// Check if address is too empty (no meaningful fields)
				$has_meaningful_data = false;
				$meaningful_fields = array( 'streetAddress', 'addressLocality', 'postalCode' );

				foreach ( $meaningful_fields as $field ) {
					if ( ! empty( $schema['address'][ $field ] ) ) {
						$has_meaningful_data = true;
						break;
					}
				}

				// Remove address if it's too incomplete
				if ( ! $has_meaningful_data ) {
					unset( $schema['address'] );
				}
			}
		}

		return $schema;
	}

	/**
	 * Fix incomplete BreadcrumbList: add missing 'item' field to ListItems
	 */
	private static function fix_breadcrumb_list( $schema ) {
		if ( empty( $schema['itemListElement'] ) || ! is_array( $schema['itemListElement'] ) ) {
			return $schema;
		}

		$base_url = home_url();

		foreach ( $schema['itemListElement'] as &$item ) {
			// If item already has 'item' field, skip
			if ( ! empty( $item['item'] ) ) {
				continue;
			}

			// If ListItem has 'name' but no 'item', derive the URL
			if ( ! empty( $item['name'] ) ) {
				// For "Home", use the base URL
				if ( 'Home' === $item['name'] || 'home' === strtolower( $item['name'] ) ) {
					$item['item'] = $base_url;
				} else {
					// For other pages, try to construct URL from name (lowercase, hyphenated)
					$slug = strtolower( str_replace( ' ', '-', $item['name'] ) );
					$item['item'] = trailingslashit( $base_url ) . $slug . '/';
				}
			}
		}

		return $schema;
	}

	/**
	 * Fix LocalBusiness schema: add missing telephone and complete address
	 */
	private static function fix_local_business( $schema ) {
		// Add missing telephone from extracted address data
		if ( empty( $schema['telephone'] ) ) {
			$phone = self::get_telephone_from_sources();
			if ( ! empty( $phone ) ) {
				$schema['telephone'] = $phone;
			}
		}

		// Clean up address: remove duplicate addresses and keep only one
		if ( ! empty( $schema['address'] ) && is_array( $schema['address'] ) ) {
			if ( is_array( reset( $schema['address'] ) ) && count( $schema['address'] ) > 1 ) {
				// If it's an array of arrays, keep only the first one
				$schema['address'] = $schema['address'][0];
			}
		}

		return $schema;
	}

	/**
	 * Fix FAQPage schema: remove HTML from answer text
	 */
	private static function fix_faq_page( $schema ) {
		if ( empty( $schema['mainEntity'] ) || ! is_array( $schema['mainEntity'] ) ) {
			return $schema;
		}

		foreach ( $schema['mainEntity'] as &$item ) {
			if ( ! is_array( $item ) || ! isset( $item['acceptedAnswer'] ) ) {
				continue;
			}

			// Remove HTML from acceptedAnswer.text
			if ( is_array( $item['acceptedAnswer'] ) && ! empty( $item['acceptedAnswer']['text'] ) ) {
				$item['acceptedAnswer']['text'] = self::strip_html_from_text( $item['acceptedAnswer']['text'] );
			}

			// Remove HTML from question.text if present
			if ( is_array( $item['name'] ) && ! empty( $item['name']['text'] ) ) {
				$item['name']['text'] = self::strip_html_from_text( $item['name']['text'] );
			} elseif ( is_string( $item['name'] ) ) {
				$item['name'] = self::strip_html_from_text( $item['name'] );
			}
		}

		return $schema;
	}

	/**
	 * Strip HTML tags from text while preserving content
	 */
	private static function strip_html_from_text( $text ) {
		// Remove HTML tags
		$text = wp_strip_all_tags( $text );
		// Decode HTML entities
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5 );
		// Normalize whitespace
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Get telephone from Yoast or footer settings
	 */
	private static function get_telephone_from_sources() {
		// Try Yoast SEO
		$yoast_phone = get_option( 'wpseo_local_phone' );
		if ( ! empty( $yoast_phone ) ) {
			return sanitize_text_field( $yoast_phone );
		}

		// Try footer settings
		$footer_phone = get_option( 'ihw_footer_phone' );
		if ( ! empty( $footer_phone ) ) {
			return sanitize_text_field( $footer_phone );
		}

		return '';
	}

	/**
	 * Fix Organization schema: remove duplicates and clean up structure
	 */
	private static function fix_organization( $schema ) {
		// Remove duplicate 'image' field if 'logo' exists
		if ( ! empty( $schema['logo'] ) && ! empty( $schema['image'] ) ) {
			unset( $schema['image'] );
		}

		// Clean up address: remove duplicate addresses and keep only one
		if ( ! empty( $schema['address'] ) && is_array( $schema['address'] ) ) {
			if ( is_array( reset( $schema['address'] ) ) && count( $schema['address'] ) > 1 ) {
				// If it's an array of arrays, keep only the first one
				$schema['address'] = $schema['address'][0];
			}
		}

		// Remove empty or incomplete addresses
		if ( ! empty( $schema['address'] ) && is_array( $schema['address'] ) ) {
			if ( empty( $schema['address']['streetAddress'] ) &&
				 empty( $schema['address']['addressLocality'] ) &&
				 empty( $schema['address']['postalCode'] ) ) {
				// Address is too incomplete, remove it
				unset( $schema['address'] );
			}
		}

		// Keep only the primary 'name' (remove shortened versions)
		// The name should be the full official name
		if ( ! empty( $schema['name'] ) ) {
			// Convert to string if it's somehow an array
			if ( is_array( $schema['name'] ) ) {
				$schema['name'] = $schema['name'][0];
			}
		}

		return $schema;
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
