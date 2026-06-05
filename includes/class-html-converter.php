<?php
/**
 * Convert a rendered page's <body> into Markdown.
 *
 * Resolves relative image/link URLs to absolute, strips noise (script/style/
 * svg), then runs league/html-to-markdown. Also collects the list of image URLs.
 *
 * @package HtmlToMarkdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\HTMLToMarkdown\HtmlConverter;

class H2MD_HTML_Converter {

	/**
	 * CSS-ish container: 'body' or a single tag/id/class selector.
	 *
	 * @var string
	 */
	private $selector;

	/**
	 * Site host (scheme://host[:port]) used to detect internal links.
	 *
	 * @var string
	 */
	private $site_host;

	/**
	 * Collected absolute image URLs for the last conversion.
	 *
	 * @var array<int,string>
	 */
	private $images = array();

	/**
	 * @param string $selector  Content selector (default 'body').
	 * @param string $site_url  Site home URL — used to recognise internal links.
	 */
	public function __construct( $selector = 'body', $site_url = '' ) {
		$this->selector  = $selector ? $selector : 'body';
		$site_url        = $site_url ?: home_url();
		$parts           = wp_parse_url( $site_url );
		$this->site_host = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
		if ( isset( $parts['port'] ) ) {
			$this->site_host .= ':' . $parts['port'];
		}
	}

	/**
	 * Image URLs found in the last convert() call.
	 *
	 * @return array<int,string>
	 */
	public function get_images() {
		return $this->images;
	}

	/**
	 * Convert page HTML to Markdown.
	 *
	 * @param string $html      Full page HTML.
	 * @param string $page_url  URL of the page (for resolving relatives).
	 * @return string Markdown.
	 */
	public function convert( $html, $page_url ) {
		$this->images = array();

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		$this->strip_noise( $dom );
		$this->absolutize_urls( $dom, $page_url );

		$container = $this->find_container( $dom );
		if ( null === $container ) {
			return '';
		}

		$inner = $this->inner_html( $dom, $container );

		$converter = new HtmlConverter(
			array(
				'strip_tags'    => true,
				'hard_break'    => true,
				'header_style'  => 'atx',
				'remove_nodes'  => 'header footer nav script style noscript iframe svg form',
				'use_autolinks' => false,
			)
		);

		$markdown = $converter->convert( $inner );

		// Collapse 3+ blank lines into 2.
		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );

		return trim( $markdown );
	}

	/**
	 * Remove elements that never belong in content.
	 *
	 * @param DOMDocument $dom DOM.
	 */
	private function strip_noise( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		// Non-content structural elements and technical tags.
		$nodes = $xpath->query(
			'//header | //footer | //nav | //script | //style | //noscript | //template | //svg | //link | //meta'
		);
		if ( $nodes ) {
			foreach ( iterator_to_array( $nodes ) as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Resolve relative src/href/srcset to absolute URLs and collect images.
	 *
	 * @param DOMDocument $dom      DOM.
	 * @param string      $page_url Page URL.
	 */
	private function absolutize_urls( DOMDocument $dom, $page_url ) {
		// Images.
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$src = $img->getAttribute( 'src' );

			// Lazy-loaded images often keep the real URL in data-src.
			if ( '' === $src || $this->is_placeholder( $src ) ) {
				foreach ( array( 'data-src', 'data-lazy-src', 'data-original' ) as $attr ) {
					$candidate = $img->getAttribute( $attr );
					if ( '' !== $candidate ) {
						$src = $candidate;
						break;
					}
				}
			}

			if ( '' !== $src ) {
				$abs = $this->make_absolute( $src, $page_url );
				$img->setAttribute( 'src', $abs );
				$this->images[] = $abs;
			}
		}

		// Links: internal → relative .md path; external → keep absolute.
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( '' === $href || $this->is_special_scheme( $href ) ) {
				continue;
			}
			$abs = $this->make_absolute( $href, $page_url );
			if ( $this->is_internal( $abs ) ) {
				$a->setAttribute( 'href', $this->internal_to_relative_md( $abs, $page_url ) );
			} else {
				$a->setAttribute( 'href', $abs );
			}
		}

		$this->images = array_values( array_unique( $this->images ) );
	}

	/**
	 * Whether an absolute URL belongs to this site.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private function is_internal( $url ) {
		return 0 === strpos( $url, $this->site_host );
	}

	/**
	 * Convert an internal absolute URL to a relative path pointing at its .md file.
	 *
	 * e.g. current page https://site/about/team/, target https://site/services/seo/
	 * → ../../services/seo.md
	 *
	 * @param string $target_url  Absolute target URL (same site).
	 * @param string $current_url Absolute URL of the page being exported.
	 * @return string
	 */
	private function internal_to_relative_md( $target_url, $current_url ) {
		$target_parts  = wp_parse_url( $target_url );
		$current_parts = wp_parse_url( $current_url );

		// Preserve #fragment if present.
		$fragment = isset( $target_parts['fragment'] ) ? '#' . $target_parts['fragment'] : '';

		// Target .md path (mirrors Exporter::url_to_relpath logic).
		$target_path = isset( $target_parts['path'] ) ? trim( $target_parts['path'], '/' ) : '';
		if ( '' === $target_path ) {
			$target_md = 'index.md';
		} else {
			$segs      = array_filter( array_map( 'rawurldecode', explode( '/', $target_path ) ), 'strlen' );
			$target_md = implode( '/', array_values( $segs ) ) . '.md';
		}

		// Current page's directory within the export tree.
		$current_path = isset( $current_parts['path'] ) ? trim( $current_parts['path'], '/' ) : '';
		$current_segs = array_filter( array_map( 'rawurldecode', explode( '/', $current_path ) ), 'strlen' );
		array_pop( $current_segs ); // remove the last segment (the page slug itself).
		$current_dir = implode( '/', array_values( $current_segs ) );

		return $this->relative_path( $current_dir, $target_md ) . $fragment;
	}

	/**
	 * Compute the relative path from a directory to a target file path.
	 *
	 * Both arguments use forward slashes; $from_dir may be empty (root).
	 *
	 * @param string $from_dir  e.g. "about" or "about/team" or "".
	 * @param string $to_file   e.g. "services/seo.md".
	 * @return string
	 */
	private function relative_path( $from_dir, $to_file ) {
		$from = '' === $from_dir ? array() : explode( '/', $from_dir );
		$to   = explode( '/', $to_file );

		// Strip common leading segments.
		while ( ! empty( $from ) && ! empty( $to ) && $from[0] === $to[0] ) {
			array_shift( $from );
			array_shift( $to );
		}

		$rel = str_repeat( '../', count( $from ) ) . implode( '/', $to );

		return '' !== $rel ? $rel : './';
	}

	/**
	 * Whether an img src is a placeholder (data URI / blank gif).
	 *
	 * @param string $src Source.
	 * @return bool
	 */
	private function is_placeholder( $src ) {
		return ( 0 === strpos( $src, 'data:' ) );
	}

	/**
	 * Whether a href is a non-resolvable scheme (mailto, tel, anchor, js).
	 *
	 * @param string $href Href.
	 * @return bool
	 */
	private function is_special_scheme( $href ) {
		return (bool) preg_match( '/^(#|mailto:|tel:|javascript:|data:)/i', $href );
	}

	/**
	 * Resolve a (possibly relative) URL against the page URL.
	 *
	 * @param string $url      Raw URL.
	 * @param string $base_url Page URL.
	 * @return string
	 */
	private function make_absolute( $url, $base_url ) {
		$url = trim( $url );

		// Already absolute.
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$base = wp_parse_url( $base_url );
		if ( ! isset( $base['scheme'], $base['host'] ) ) {
			return $url;
		}

		$origin = $base['scheme'] . '://' . $base['host'];
		if ( isset( $base['port'] ) ) {
			$origin .= ':' . $base['port'];
		}

		// Protocol-relative.
		if ( 0 === strpos( $url, '//' ) ) {
			return $base['scheme'] . ':' . $url;
		}

		// Root-relative.
		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $this->normalize_path( $url );
		}

		// Path-relative.
		$base_path = isset( $base['path'] ) ? $base['path'] : '/';
		$dir       = preg_replace( '#/[^/]*$#', '/', $base_path );

		return $origin . $this->normalize_path( $dir . $url );
	}

	/**
	 * Collapse ./ and ../ segments in a URL path (preserving query/fragment).
	 *
	 * @param string $path Path possibly with query/fragment.
	 * @return string
	 */
	private function normalize_path( $path ) {
		$suffix = '';
		if ( preg_match( '/[?#]/', $path, $m, PREG_OFFSET_CAPTURE ) ) {
			$suffix = substr( $path, $m[0][1] );
			$path   = substr( $path, 0, $m[0][1] );
		}

		$segments = explode( '/', $path );
		$out      = array();
		foreach ( $segments as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $out );
			} elseif ( '.' !== $seg ) {
				$out[] = $seg;
			}
		}

		$result = implode( '/', $out );
		if ( '' === $result || '/' !== $result[0] ) {
			$result = '/' . ltrim( $result, '/' );
		}

		return $result . $suffix;
	}

	/**
	 * Find the content container node by selector.
	 *
	 * @param DOMDocument $dom DOM.
	 * @return DOMNode|null
	 */
	private function find_container( DOMDocument $dom ) {
		$selector = trim( $this->selector );

		if ( '' === $selector || 'body' === strtolower( $selector ) ) {
			$body = $dom->getElementsByTagName( 'body' );
			return $body->length > 0 ? $body->item( 0 ) : null;
		}

		$xpath = new DOMXPath( $dom );
		$query = $this->selector_to_xpath( $selector );
		$nodes = $xpath->query( $query );

		if ( $nodes && $nodes->length > 0 ) {
			return $nodes->item( 0 );
		}

		// Fallback to body if selector misses.
		$body = $dom->getElementsByTagName( 'body' );
		return $body->length > 0 ? $body->item( 0 ) : null;
	}

	/**
	 * Translate a simple selector (#id, .class, tag) to XPath.
	 *
	 * @param string $selector Selector.
	 * @return string
	 */
	private function selector_to_xpath( $selector ) {
		if ( 0 === strpos( $selector, '#' ) ) {
			$id = substr( $selector, 1 );
			return sprintf( '//*[@id="%s"]', $id );
		}
		if ( 0 === strpos( $selector, '.' ) ) {
			$class = substr( $selector, 1 );
			return sprintf( '//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $class );
		}
		return '//' . $selector;
	}

	/**
	 * Serialize the inner HTML of a node.
	 *
	 * @param DOMDocument $dom  DOM.
	 * @param DOMNode     $node Node.
	 * @return string
	 */
	private function inner_html( DOMDocument $dom, DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}
}
