<?php
/**
 * Plugin Name:       HTML → Markdown Site Export
 * Plugin URI:        https://langate.local/
 * Description:        Обходить карту сайту (WP sitemap) і зберігає кожну зрендерену сторінку в Markdown, включно з посиланнями на зображення та SEO-даними з <head>.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Langate
 * License:           GPL-2.0-or-later
 * Text Domain:       html-to-markdown
 *
 * @package HtmlToMarkdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'H2MD_VERSION', '1.0.0' );
define( 'H2MD_FILE', __FILE__ );
define( 'H2MD_DIR', plugin_dir_path( __FILE__ ) );
define( 'H2MD_URL', plugin_dir_url( __FILE__ ) );
define( 'H2MD_OPTION', 'h2md_settings' );
define( 'H2MD_QUEUE_TRANSIENT', 'h2md_export_queue' );

// Composer autoloader (league/html-to-markdown).
if ( file_exists( H2MD_DIR . 'vendor/autoload.php' ) ) {
	require_once H2MD_DIR . 'vendor/autoload.php';
}

// Plugin classes.
require_once H2MD_DIR . 'includes/class-sitemap-crawler.php';
require_once H2MD_DIR . 'includes/class-page-fetcher.php';
require_once H2MD_DIR . 'includes/class-seo-extractor.php';
require_once H2MD_DIR . 'includes/class-html-converter.php';
require_once H2MD_DIR . 'includes/class-exporter.php';
require_once H2MD_DIR . 'includes/class-admin-page.php';
require_once H2MD_DIR . 'includes/class-plugin.php';

/**
 * Default settings.
 *
 * @return array<string,mixed>
 */
function h2md_default_settings() {
	$uploads = wp_upload_dir();

	return array(
		'sitemap_url'   => '', // empty = auto-detect.
		'export_dir'    => trailingslashit( $uploads['basedir'] ) . 'markdown-export',
		'batch_size'    => 5,
		'base_url'      => '', // empty = home_url().
		'auth_user'     => '',
		'auth_pass'     => '',
		'content_selector' => 'body',
	);
}

/**
 * Get merged settings.
 *
 * @return array<string,mixed>
 */
function h2md_get_settings() {
	$saved = get_option( H2MD_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( h2md_default_settings(), $saved );
}

register_activation_hook( __FILE__, array( 'H2MD_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'H2MD_Plugin', 'instance' ) );
