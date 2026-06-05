<?php
/**
 * Orchestrate exporting a single URL: fetch → SEO → convert → write file.
 *
 * @package HtmlToMarkdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2MD_Exporter {

	/**
	 * Settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/** @var H2MD_Page_Fetcher */
	private $fetcher;

	/** @var H2MD_SEO_Extractor */
	private $seo;

	/** @var H2MD_HTML_Converter */
	private $converter;

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	public function __construct( array $settings ) {
		$this->settings  = $settings;
		$this->fetcher   = new H2MD_Page_Fetcher( $settings );
		$this->seo       = new H2MD_SEO_Extractor();
		$this->converter = new H2MD_HTML_Converter( $settings['content_selector'], home_url() );
	}

	/**
	 * Absolute export directory.
	 *
	 * @return string
	 */
	public function export_dir() {
		return untrailingslashit( $this->settings['export_dir'] );
	}

	/**
	 * Export a single URL.
	 *
	 * @param string $url Page URL.
	 * @return array{url:string,file:string}|WP_Error
	 */
	public function export_url( $url ) {
		$html = $this->fetcher->fetch( $url );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$seo      = $this->seo->extract( $html );
		$markdown = $this->converter->convert( $html, $url );
		$images   = $this->converter->get_images();

		$front  = $this->build_front_matter( $url, $seo, $images );
		$content = $front . "\n" . $markdown . "\n";

		$rel  = $this->url_to_relpath( $url );
		$path = trailingslashit( $this->export_dir() ) . $rel;

		$written = $this->write_file( $path, $content );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'url'   => $url,
			'file'  => $rel,
			'title' => $seo['title'],
		);
	}

	/**
	 * Build YAML front matter.
	 *
	 * @param string               $url    Page URL.
	 * @param array<string,mixed>  $seo    SEO data.
	 * @param array<int,string>    $images Image URLs.
	 * @return string
	 */
	private function build_front_matter( $url, array $seo, array $images ) {
		$lines   = array( '---' );
		$lines[] = 'url: ' . $this->yaml_scalar( $url );
		$lines[] = 'slug: ' . $this->yaml_scalar( $this->url_to_slug( $url ) );
		$lines[] = 'title: ' . $this->yaml_scalar( $seo['title'] );
		$lines[] = 'description: ' . $this->yaml_scalar( $seo['description'] );

		if ( '' !== $seo['keywords'] ) {
			$lines[] = 'keywords: ' . $this->yaml_scalar( $seo['keywords'] );
		}
		if ( '' !== $seo['canonical'] ) {
			$lines[] = 'canonical: ' . $this->yaml_scalar( $seo['canonical'] );
		}
		if ( '' !== $seo['robots'] ) {
			$lines[] = 'robots: ' . $this->yaml_scalar( $seo['robots'] );
		}
		if ( '' !== $seo['lang'] ) {
			$lines[] = 'lang: ' . $this->yaml_scalar( $seo['lang'] );
		}

		if ( ! empty( $seo['og'] ) ) {
			$lines[] = 'og:';
			foreach ( $seo['og'] as $key => $value ) {
				$lines[] = '  ' . $this->yaml_scalar( $key, true ) . ': ' . $this->yaml_scalar( $value );
			}
		}

		if ( ! empty( $seo['twitter'] ) ) {
			$lines[] = 'twitter:';
			foreach ( $seo['twitter'] as $key => $value ) {
				$lines[] = '  ' . $this->yaml_scalar( $key, true ) . ': ' . $this->yaml_scalar( $value );
			}
		}

		if ( ! empty( $images ) ) {
			$lines[] = 'images:';
			foreach ( $images as $img ) {
				$lines[] = '  - ' . $this->yaml_scalar( $img );
			}
		}

		if ( ! empty( $seo['schema'] ) ) {
			$json    = wp_json_encode( $seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$lines[] = 'schema: ' . $this->yaml_scalar( $json );
		}

		$lines[] = 'exported_at: ' . $this->yaml_scalar( current_time( 'mysql' ) );
		$lines[] = '---';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Quote a scalar for YAML safely.
	 *
	 * @param string $value     Value.
	 * @param bool   $plain_key Whether this is a key (skip quoting if simple).
	 * @return string
	 */
	private function yaml_scalar( $value, $plain_key = false ) {
		$value = (string) $value;

		if ( $plain_key && preg_match( '/^[A-Za-z0-9_:.\-]+$/', $value ) ) {
			return $value;
		}

		// Always double-quote, escaping backslashes and quotes.
		$escaped = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
		$escaped = str_replace( array( "\r\n", "\n", "\r" ), ' ', $escaped );

		return '"' . $escaped . '"';
	}

	/**
	 * Convert a URL into a relative file path mirroring its structure.
	 *
	 * @param string $url URL.
	 * @return string e.g. "about/team.md" or "index.md".
	 */
	public function url_to_relpath( $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$path  = trim( $path, '/' );

		if ( '' === $path ) {
			return 'index.md';
		}

		$segments = array_map( array( $this, 'sanitize_segment' ), explode( '/', $path ) );
		$segments = array_filter( $segments, 'strlen' );

		return implode( '/', $segments ) . '.md';
	}

	/**
	 * Slug (last path segment) of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function url_to_slug( $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
		if ( '' === $path ) {
			return 'home';
		}
		$segments = explode( '/', $path );
		return end( $segments );
	}

	/**
	 * Sanitize one path segment for the filesystem.
	 *
	 * @param string $segment Segment.
	 * @return string
	 */
	private function sanitize_segment( $segment ) {
		$segment = sanitize_file_name( rawurldecode( $segment ) );
		return $segment;
	}

	/**
	 * Write content to a file, creating directories as needed.
	 *
	 * @param string $path    Absolute path.
	 * @param string $content File content.
	 * @return true|WP_Error
	 */
	public function write_file( $path, $content ) {
		$base = wp_normalize_path( $this->export_dir() );
		$path = wp_normalize_path( $path );

		// Guard: never write outside the export dir.
		if ( 0 !== strpos( $path, $base ) ) {
			return new WP_Error( 'h2md_path_escape', __( 'Спроба запису поза папкою експорту.', 'html-to-markdown' ) );
		}

		$dir = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'h2md_mkdir', __( 'Не вдалося створити папку експорту.', 'html-to-markdown' ) );
		}

		$result = file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $result ) {
			return new WP_Error( 'h2md_write', __( 'Не вдалося записати файл.', 'html-to-markdown' ) );
		}

		return true;
	}

	/**
	 * Ensure the export dir exists and is protected.
	 *
	 * @return true|WP_Error
	 */
	public function prepare_dir() {
		$dir = $this->export_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'h2md_mkdir', __( 'Не вдалося створити папку експорту.', 'html-to-markdown' ) );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return true;
	}

	/**
	 * Write the index/sitemap markdown listing all exported pages.
	 *
	 * @param array<int,array{url:string,file:string,title:string}> $pages Pages.
	 * @return true|WP_Error
	 */
	public function write_index( array $pages ) {
		$lines   = array( '# Карта сайту', '' );
		$lines[] = '> Згенеровано ' . current_time( 'mysql' ) . ' — ' . count( $pages ) . ' сторінок.';
		$lines[] = '';

		usort(
			$pages,
			function ( $a, $b ) {
				return strcmp( $a['file'], $b['file'] );
			}
		);

		foreach ( $pages as $page ) {
			$label = '' !== $page['title'] ? $page['title'] : $page['url'];
			$lines[] = sprintf( '- [%s](%s) — <%s>', $this->escape_md( $label ), $page['file'], $page['url'] );
		}

		$path = trailingslashit( $this->export_dir() ) . 'index.md';
		return $this->write_file( $path, implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Minimal Markdown escaping for link labels.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape_md( $text ) {
		return str_replace( array( '[', ']' ), array( '\[', '\]' ), $text );
	}
}
