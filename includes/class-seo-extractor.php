<?php
/**
 * Extract SEO metadata from a rendered page's <head>.
 *
 * @package HtmlToMarkdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2MD_SEO_Extractor {

	/**
	 * Parse SEO data from raw HTML.
	 *
	 * @param string $html Full page HTML.
	 * @return array<string,mixed>
	 */
	public function extract( $html ) {
		$data = array(
			'title'       => '',
			'description' => '',
			'keywords'    => '',
			'canonical'   => '',
			'robots'      => '',
			'lang'        => '',
			'og'          => array(),
			'twitter'     => array(),
			'schema'      => array(),
		);

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		// Force UTF-8 handling.
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		// <html lang>.
		$html_nodes = $dom->getElementsByTagName( 'html' );
		if ( $html_nodes->length > 0 ) {
			$data['lang'] = $html_nodes->item( 0 )->getAttribute( 'lang' );
		}

		// <title>.
		$titles = $dom->getElementsByTagName( 'title' );
		if ( $titles->length > 0 ) {
			$data['title'] = trim( $titles->item( 0 )->textContent );
		}

		// <meta> tags.
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			$name     = strtolower( $meta->getAttribute( 'name' ) );
			$property = strtolower( $meta->getAttribute( 'property' ) );
			$content  = $meta->getAttribute( 'content' );

			if ( '' === $content ) {
				continue;
			}

			if ( 'description' === $name ) {
				$data['description'] = trim( $content );
			} elseif ( 'keywords' === $name ) {
				$data['keywords'] = trim( $content );
			} elseif ( 'robots' === $name ) {
				$data['robots'] = trim( $content );
			} elseif ( 0 === strpos( $property, 'og:' ) ) {
				$data['og'][ $property ] = trim( $content );
			} elseif ( 0 === strpos( $name, 'twitter:' ) ) {
				$data['twitter'][ $name ] = trim( $content );
			}
		}

		// <link rel="canonical">.
		foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
			if ( 'canonical' === strtolower( $link->getAttribute( 'rel' ) ) ) {
				$data['canonical'] = trim( $link->getAttribute( 'href' ) );
				break;
			}
		}

		// JSON-LD schema blocks.
		foreach ( $dom->getElementsByTagName( 'script' ) as $script ) {
			if ( 'application/ld+json' === strtolower( $script->getAttribute( 'type' ) ) ) {
				$raw     = trim( $script->textContent );
				$decoded = json_decode( $raw, true );
				if ( JSON_ERROR_NONE === json_last_error() && null !== $decoded ) {
					$data['schema'][] = $decoded;
				}
			}
		}

		return $data;
	}
}
