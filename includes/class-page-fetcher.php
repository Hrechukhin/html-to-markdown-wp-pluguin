<?php
/**
 * Fetch the rendered HTML of a single front-end page.
 *
 * @package HtmlToMarkdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2MD_Page_Fetcher {

	/**
	 * Settings array.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build request args, with optional basic auth.
	 *
	 * @return array<string,mixed>
	 */
	private function request_args() {
		$args = array(
			'timeout'     => 30,
			'redirection' => 5,
			'user-agent'  => 'HTML-to-Markdown-Exporter/' . H2MD_VERSION . '; ' . home_url(),
			'sslverify'   => false,
		);

		if ( ! empty( $this->settings['auth_user'] ) ) {
			$args['headers'] = array(
				'Authorization' => 'Basic ' . base64_encode( $this->settings['auth_user'] . ':' . $this->settings['auth_pass'] ),
			);
		}

		return $args;
	}

	/**
	 * Map a sitemap URL onto the configured base URL (for loopback/override).
	 *
	 * @param string $url Original URL.
	 * @return string
	 */
	private function map_url( $url ) {
		if ( empty( $this->settings['base_url'] ) ) {
			return $url;
		}

		$base  = untrailingslashit( $this->settings['base_url'] );
		$parts = wp_parse_url( $url );
		if ( ! isset( $parts['path'] ) ) {
			return $base;
		}

		$path  = $parts['path'];
		$path .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return $base . $path;
	}

	/**
	 * Fetch the rendered HTML.
	 *
	 * @param string $url Page URL.
	 * @return string|WP_Error HTML body or error.
	 */
	public function fetch( $url ) {
		$request_url = $this->map_url( $url );

		$response = wp_remote_get( $request_url, $this->request_args() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'h2md_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'HTTP %d під час завантаження сторінки.', 'html-to-markdown' ), $code )
			);
		}

		$ctype = wp_remote_retrieve_header( $response, 'content-type' );
		if ( $ctype && false === stripos( $ctype, 'html' ) ) {
			return new WP_Error(
				'h2md_not_html',
				/* translators: %s: content type */
				sprintf( __( 'Сторінка не є HTML (%s).', 'html-to-markdown' ), $ctype )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'h2md_empty_body', __( 'Порожня відповідь сторінки.', 'html-to-markdown' ) );
		}

		return $body;
	}
}
