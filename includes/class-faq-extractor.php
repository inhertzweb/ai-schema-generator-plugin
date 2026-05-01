<?php
/**
 * Extract FAQ content from posts/pages
 */

namespace IHW_AISG;

class FaqExtractor {
	/**
	 * Extract FAQs from post content
	 */
	public static function extract( $post_id ) {
		$content = get_post_field( 'post_content', $post_id );
		if ( empty( $content ) ) {
			return array();
		}
		$faqs    = array();

		// Try Gutenberg FAQ blocks
		$gutenberg_faqs = self::extract_gutenberg_faqs( $content );
		if ( ! empty( $gutenberg_faqs ) ) {
			return $gutenberg_faqs;
		}

		// Try HTML details/summary pattern
		$details_faqs = self::extract_details_faqs( $content );
		if ( ! empty( $details_faqs ) ) {
			return $details_faqs;
		}

		// Try heading + paragraph pattern
		$heading_faqs = self::extract_heading_faqs( $content );
		if ( ! empty( $heading_faqs ) ) {
			return $heading_faqs;
		}

		return array();
	}

	/**
	 * Extract from Gutenberg FAQ/details blocks
	 */
	private static function extract_gutenberg_faqs( $content ) {
		$faqs = array();

		// Match wp:faq or wp:details blocks
		if ( preg_match_all( '/<!-- wp:(faq|details)[^>]*?({.*?}) -->/', $content, $matches ) ) {
			foreach ( $matches[2] as $json_str ) {
				$block_data = json_decode( $json_str, true );
				if ( ! isset( $block_data['questions'] ) && ! isset( $block_data['summary'] ) ) {
					continue;
				}

				// Handle wp:faq format
				if ( isset( $block_data['questions'] ) ) {
					foreach ( $block_data['questions'] as $q ) {
						if ( isset( $q['question'], $q['answer'] ) ) {
							$faqs[] = array(
								'question' => sanitize_text_field( wp_strip_all_tags( $q['question'] ) ),
								'answer'   => sanitize_text_field( wp_strip_all_tags( $q['answer'] ) ),
							);
						}
					}
				}
			}
		}

		return $faqs;
	}

	/**
	 * Extract from <details><summary>Q</summary>A</details> pattern
	 */
	private static function extract_details_faqs( $content ) {
		$faqs = array();

		if ( preg_match_all( '/<details[^>]*?>\s*<summary[^>]*?>(.+?)<\/summary>\s*(.+?)<\/details>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $i => $question ) {
				$answer = $matches[2][ $i ] ?? '';

				$faqs[] = array(
					'question' => sanitize_text_field( wp_strip_all_tags( $question ) ),
					'answer'   => sanitize_text_field( wp_strip_all_tags( $answer ) ),
				);
			}
		}

		return $faqs;
	}

	/**
	 * Extract from heading + paragraph pattern (headings ending in ?)
	 */
	private static function extract_heading_faqs( $content ) {
		$faqs = array();

		if ( empty( $content ) ) {
			return $faqs;
		}

		// Remove tags first to parse structure
		$dom = new \DOMDocument();
		$options = defined( 'LIBXML_HTML_NOXMLNS' ) ? LIBXML_HTML_NOXMLNS : 2;
		try {
			@$dom->loadHTML( mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ), $options );
		} catch ( \Exception $e ) {
			return $faqs;
		}

		$headings = $dom->getElementsByTagName( 'h2' );
		if ( $headings->length === 0 ) {
			$headings = $dom->getElementsByTagName( 'h3' );
		}

		foreach ( $headings as $heading ) {
			$text = trim( $heading->textContent );

			// Check if ends with ?
			if ( '?' !== substr( $text, -1 ) ) {
				continue;
			}

			// Get next paragraph (answer)
			$next_node = $heading->nextSibling;
			while ( $next_node ) {
				if ( 'p' === $next_node->nodeName ) {
					$answer = trim( $next_node->textContent );
					$faqs[]  = array(
						'question' => sanitize_text_field( $text ),
						'answer'   => sanitize_text_field( $answer ),
					);
					break;
				}
				$next_node = $next_node->nextSibling;
			}
		}

		return $faqs;
	}
}
