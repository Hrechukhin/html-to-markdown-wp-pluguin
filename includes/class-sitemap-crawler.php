<?php
/**
 * Discover and collect all page URLs from the WordPress sitemap.
 *
 * @package HtmlToMarkdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2MD_Sitemap_Crawler {

	/**
	 * Settings array.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Visited sitemap URLs (avoid loops).
	 *
	 * @var array<string,bool>
	 */
	private $seen_sitemaps = array();

	/**
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Base site URL (override-aware).
	 *
	 * @return string
	 */
	private function base_url() {
		$base = ! empty( $this->settings['base_url'] ) ? $this->settings['base_url'] : home_url();
		return untrailingslashit( $base );
	}

	/**
	 * Resolve the sitemap entry point.
	 *
	 * @return string|WP_Error Sitemap URL or error.
	 */
	private function resolve_sitemap_url() {
		if ( ! empty( $this->settings['sitemap_url'] ) ) {
			return $this->settings['sitemap_url'];
		}

		$candidates = array(
			$this->base_url() . '/wp-sitemap.xml',     // WP core.
			$this->base_url() . '/sitemap_index.xml',  // Yoast.
			$this->base_url() . '/sitemap.xml',        // generic / Rank Math.
		);

		foreach ( $candidates as $url ) {
			$response = wp_remote_head( $url, $this->request_args() );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				return $url;
			}
		}

		return new WP_Error( 'h2md_no_sitemap', __( 'Could not find sitemap. Please enter the URL manually in settings.', 'html-to-markdown' ) );
	}

	/**
	 * Build request args, with optional basic auth.
	 *
	 * @return array<string,mixed>
	 */
	private function request_args() {
		$args = array(
			'timeout'     => 20,
			'redirection' => 5,
			'user-agent'  => 'HTML-to-Markdown-Exporter/' . H2MD_VERSION . '; ' . home_url(),
			'sslverify'   => false, // local dev hosts often use self-signed certs.
		);

		if ( ! empty( $this->settings['auth_user'] ) ) {
			$args['headers'] = array(
				'Authorization' => 'Basic ' . base64_encode( $this->settings['auth_user'] . ':' . $this->settings['auth_pass'] ),
			);
		}

		return $args;
	}

	/**
	 * Collect every page URL from the sitemap (recursively follows indexes).
	 *
	 * @return array{urls:array<int,string>}|WP_Error
	 */
	public function collect() {
		$entry = $this->resolve_sitemap_url();
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		$urls = $this->walk( $entry );
		$urls = array_values( array_unique( array_filter( $urls ) ) );

		if ( empty( $urls ) ) {
			return new WP_Error( 'h2md_empty_sitemap', __( 'Sitemap found but it contains no URLs.', 'html-to-markdown' ) );
		}

		return array(
			'sitemap' => $entry,
			'urls'    => $urls,
		);
	}

	/**
	 * Recursively read a sitemap or sitemap index.
	 *
	 * @param string $sitemap_url Sitemap URL.
	 * @return array<int,string> Page URLs.
	 */
	private function walk( $sitemap_url ) {
		if ( isset( $this->seen_sitemaps[ $sitemap_url ] ) ) {
			return array();
		}
		$this->seen_sitemaps[ $sitemap_url ] = true;

		$response = wp_remote_get( $sitemap_url, $this->request_args() );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return array();
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		libxml_clear_errors();

		if ( false === $xml ) {
			return array();
		}

		$name = $xml->getName();

		// Sitemap index: collect nested <sitemap><loc> and recurse.
		if ( 'sitemapindex' === $name ) {
			$collected = array();
			foreach ( $xml->sitemap as $node ) {
				$loc = trim( (string) $node->loc );
				if ( '' !== $loc ) {
					$collected = array_merge( $collected, $this->walk( $loc ) );
				}
			}
			return $collected;
		}

		// URL set: collect <url><loc>.
		$urls = array();
		foreach ( $xml->url as $node ) {
			$loc = trim( (string) $node->loc );
			if ( '' !== $loc ) {
				$urls[] = $loc;
			}
		}

		return $urls;
	}
}
