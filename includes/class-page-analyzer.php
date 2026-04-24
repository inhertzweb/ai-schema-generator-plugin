<?php
/**
 * Analyze page content and extract structured data
 */

namespace IHW_AISG;

class PageAnalyzer {
	/**
	 * Analyze a post/page and return structured data
	 */
	public static function analyze( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		// Extract FAQs
		$faqs = FaqExtractor::extract( $post_id );

		// Infer schema type
		$schema_type_hint = self::infer_schema_type( $post );

		return array(
			'post_id'          => $post_id,
			'post_type'        => $post->post_type,
			'title'            => get_the_title( $post_id ),
			'permalink'        => get_permalink( $post_id ),
			'content_clean'    => self::get_clean_content( $post_id ),
			'excerpt'          => $post->post_excerpt,
			'meta_title'       => self::get_meta_title( $post_id ),
			'meta_desc'        => self::get_meta_description( $post_id ),
			'categories'       => self::get_categories( $post_id ),
			'tags'             => self::get_tags( $post_id ),
			'author'           => get_the_author_meta( 'display_name', $post->post_author ),
			'date_published'   => mysql2date( 'c', $post->post_date ),
			'date_modified'    => mysql2date( 'c', $post->post_modified ),
			'featured_image'   => self::get_featured_image_url( $post_id ),
			'logo_url'         => Settings::get_logo_url(),
			'faqs'             => $faqs,
			'schema_type_hint' => $schema_type_hint,
		);
	}

	/**
	 * Get clean content (plain text, truncated)
	 */
	private static function get_clean_content( $post_id ) {
		$content = get_post_field( 'post_content', $post_id );
		// Remove shortcodes
		$content = strip_shortcodes( $content );
		// Remove HTML tags
		$content = wp_strip_all_tags( $content );
		// Trim to 3000 characters
		$content = substr( $content, 0, 3000 );
		return trim( $content );
	}

	/**
	 * Get meta title (from SEO plugins or fallback)
	 */
	private static function get_meta_title( $post_id ) {
		// Check Yoast SEO
		if ( function_exists( 'YoastSEO' ) ) {
			$yoast_title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
			if ( ! empty( $yoast_title ) ) {
				return $yoast_title;
			}
		}
		// Check RankMath
		if ( class_exists( 'RankMath' ) ) {
			$rank_title = get_post_meta( $post_id, 'rank_math_title', true );
			if ( ! empty( $rank_title ) ) {
				return $rank_title;
			}
		}
		// Fallback to post title
		return get_the_title( $post_id );
	}

	/**
	 * Get meta description
	 */
	private static function get_meta_description( $post_id ) {
		// Check Yoast SEO
		if ( function_exists( 'YoastSEO' ) ) {
			$yoast_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
			if ( ! empty( $yoast_desc ) ) {
				return $yoast_desc;
			}
		}
		// Check RankMath
		if ( class_exists( 'RankMath' ) ) {
			$rank_desc = get_post_meta( $post_id, 'rank_math_description', true );
			if ( ! empty( $rank_desc ) ) {
				return $rank_desc;
			}
		}
		// Fallback to excerpt
		return get_post_field( 'post_excerpt', $post_id );
	}

	/**
	 * Get categories
	 */
	private static function get_categories( $post_id ) {
		$categories = get_the_category( $post_id );
		if ( empty( $categories ) ) {
			return array();
		}
		return array_map(
			function ( $cat ) {
				return $cat->name;
			},
			$categories
		);
	}

	/**
	 * Get tags
	 */
	private static function get_tags( $post_id ) {
		$tags = get_the_tags( $post_id );
		if ( ! $tags ) {
			return array();
		}
		return array_map(
			function ( $tag ) {
				return $tag->name;
			},
			$tags
		);
	}

	/**
	 * Get featured image URL
	 */
	private static function get_featured_image_url( $post_id ) {
		$image_id = get_post_thumbnail_id( $post_id );
		if ( ! $image_id ) {
			return '';
		}
		$image = wp_get_attachment_image_src( $image_id, 'full' );
		return isset( $image[0] ) ? $image[0] : '';
	}

	/**
	 * Infer schema type from post structure and content
	 */
	private static function infer_schema_type( $post ) {
		$slug     = $post->post_name;
		$title    = get_the_title( $post->ID );
		$content  = get_post_field( 'post_content', $post->ID );
		$content_lower = strtolower( $content . ' ' . $title );

		// Homepage
		if ( intval( get_option( 'page_on_front' ) ) === $post->ID ) {
			return 'LocalBusiness,Organization';
		}

		// ===== HOSPITALITY & ACCOMMODATION =====
		if ( self::match_patterns( $slug, [ 'hotel', 'albergo', 'resort', 'lodge', 'hostel', 'motel', 'accommodation', 'alloggio' ] ) ) {
			return 'Hotel';
		}
		if ( self::match_patterns( $slug, [ 'bed and breakfast', 'b&b', 'bb' ] ) ) {
			return 'BedAndBreakfast';
		}

		// ===== FOOD & BEVERAGE =====
		if ( self::match_patterns( $slug, [ 'ristorante', 'restaurant', 'pizzeria', 'trattoria', 'osteria', 'cucina', 'food' ] ) ) {
			return 'Restaurant';
		}
		if ( self::match_patterns( $slug, [ 'bar', 'cafe', 'caffè', 'coffee', 'pub', 'lounge', 'bistro' ] ) ) {
			return 'BarOrPub';
		}

		// ===== HEALTHCARE =====
		if ( self::match_patterns( $slug, [ 'medico', 'doctor', 'dentista', 'dentist', 'ospedale', 'hospital', 'clinica', 'clinic', 'medica' ] ) ) {
			return 'MedicalBusiness';
		}
		if ( self::match_patterns( $slug, [ 'avvocato', 'attorney', 'lawyer', 'legale', 'studio legale' ] ) ) {
			return 'Attorney';
		}
		if ( self::match_patterns( $slug, [ 'accountant', 'commercialista', 'geometra', 'ragioniere', 'cpa' ] ) ) {
			return 'Accountant';
		}

		// ===== REAL ESTATE =====
		if ( self::match_patterns( $slug, [ 'immobiliare', 'real estate', 'proprietà', 'property', 'affitto', 'vendita' ] ) ) {
			return 'RealEstateAgent';
		}

		// ===== EDUCATION =====
		if ( self::match_patterns( $slug, [ 'scuola', 'school', 'università', 'university', 'corso', 'corso online', 'formazione', 'training' ] ) ) {
			return 'EducationalOrganization';
		}

		// ===== EVENTS & ENTERTAINMENT =====
		if ( self::match_patterns( $slug, [ 'evento', 'event', 'concerto', 'concert', 'teatro', 'theater', 'spettacolo', 'festival' ] ) ) {
			return 'Event';
		}
		if ( self::match_patterns( $slug, [ 'film', 'movie', 'cinema', 'video' ] ) ) {
			return 'Movie';
		}
		if ( self::match_patterns( $slug, [ 'musica', 'music', 'album', 'canzone', 'song' ] ) ) {
			return 'MusicAlbum';
		}

		// ===== PRODUCTS & SHOPPING =====
		if ( self::match_patterns( $slug, [ 'prodotto', 'product', 'shop', 'negozio', 'store', 'shop', 'marketplace', 'ecommerce', 'catalogo' ] ) ) {
			return 'Product';
		}

		// ===== AUTOMOTIVE =====
		if ( self::match_patterns( $slug, [ 'auto', 'car', 'automobile', 'macchina', 'moto', 'motocicletta', 'scooter', 'veicolo', 'vehicle', 'concessionario', 'dealer' ] ) ) {
			return 'AutoRepair';
		}

		// ===== BEAUTY & PERSONAL CARE =====
		if ( self::match_patterns( $slug, [ 'parrucchiere', 'barbershop', 'barber', 'salone', 'salon', 'spa', 'beauty', 'estetica' ] ) ) {
			return 'BeautySalon';
		}

		// ===== FITNESS & WELLNESS =====
		if ( self::match_patterns( $slug, [ 'palestra', 'gym', 'fitness', 'yoga', 'pilates', 'wellness', 'wellness center' ] ) ) {
			return 'HealthAndBeautyBusiness';
		}

		// ===== ENTERTAINMENT & LEISURE =====
		if ( self::match_patterns( $slug, [ 'attrazione', 'attraction', 'parco', 'park', 'museo', 'museum', 'galleria', 'gallery', 'turismo', 'tourism' ] ) ) {
			return 'TouristAttraction';
		}

		// ===== LEGAL BUSINESS =====
		if ( self::match_patterns( $slug, [ 'governo', 'government', 'ufficio pubblico', 'public office', 'comune', 'municipio' ] ) ) {
			return 'GovernmentOffice';
		}

		// ===== PROFESSIONAL SERVICES =====
		if ( self::match_patterns( $slug, [ 'consulenza', 'consulting', 'consulente', 'consultant', 'agenzia', 'agency', 'agenzia immobiliare', 'agenzia viaggi' ] ) ) {
			return 'ProfessionalService';
		}

		// ===== CONTENT PATTERNS =====
		// Blog post
		if ( 'post' === $post->post_type ) {
			return 'BlogPosting';
		}

		// Service page (with pricing, features, or "service" terminology)
		if ( self::match_patterns( $slug, [ 'serviz', 'servizio', 'service', 'offerta', 'offer' ] ) ||
		     ( strpos( $content_lower, 'prezzo' ) !== false || strpos( $content_lower, 'price' ) !== false ) ) {
			return 'Service';
		}

		// About/Company page
		if ( self::match_patterns( $slug, [ 'chi-siamo', 'about', 'about-us', 'chi siamo', 'azienda', 'company', 'team' ] ) ) {
			return 'Organization';
		}

		// FAQ page
		if ( self::match_patterns( $slug, [ 'faq', 'domande', 'questions', 'domande frequenti', 'frequently asked' ] ) ) {
			return 'FAQPage';
		}

		// Review/Testimonials page
		if ( self::match_patterns( $slug, [ 'review', 'recensione', 'testimonianze', 'testimonial', 'valutazione', 'rating' ] ) ) {
			return 'Review';
		}

		// Contact page
		if ( self::match_patterns( $slug, [ 'contatti', 'contact', 'contattaci', 'contact-us', 'modulo contatti' ] ) ) {
			return 'Organization';
		}

		// Default
		return 'WebPage';
	}

	/**
	 * Helper: match slug against pattern list
	 */
	private static function match_patterns( $slug, $patterns ) {
		$slug_lower = strtolower( $slug );
		foreach ( $patterns as $pattern ) {
			if ( false !== stripos( $slug_lower, strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}
}
