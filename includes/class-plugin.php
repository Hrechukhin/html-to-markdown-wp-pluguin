<?php
/**
 * Main plugin controller: hooks, admin menu, AJAX endpoints.
 *
 * @package HtmlToMarkdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2MD_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var H2MD_Plugin|null
	 */
	private static $instance = null;

	/** @var H2MD_Admin_Page */
	private $admin;

	/**
	 * Boot the singleton.
	 *
	 * @return H2MD_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->admin = new H2MD_Admin_Page();

		add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
		add_action( 'admin_init', array( $this->admin, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) );

		add_action( 'wp_ajax_h2md_build_queue', array( $this, 'ajax_build_queue' ) );
		add_action( 'wp_ajax_h2md_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_h2md_finish', array( $this, 'ajax_finish' ) );
	}

	/**
	 * Activation: seed default options.
	 */
	public static function activate() {
		if ( false === get_option( H2MD_OPTION ) ) {
			add_option( H2MD_OPTION, h2md_default_settings() );
		}
	}

	/**
	 * Shared security check for AJAX requests.
	 */
	private function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'html-to-markdown' ) ), 403 );
		}
		check_ajax_referer( 'h2md_ajax', 'nonce' );
	}

	/**
	 * AJAX: crawl the sitemap and store the queue.
	 */
	public function ajax_build_queue() {
		$this->verify_request();

		$settings = h2md_get_settings();
		$crawler  = new H2MD_Sitemap_Crawler( $settings );
		$result   = $crawler->collect();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Prepare export dir up front.
		$exporter = new H2MD_Exporter( $settings );
		$prepared = $exporter->prepare_dir();
		if ( is_wp_error( $prepared ) ) {
			wp_send_json_error( array( 'message' => $prepared->get_error_message() ) );
		}

		set_transient( H2MD_QUEUE_TRANSIENT, $result['urls'], HOUR_IN_SECONDS );
		// Reset the "done" accumulator.
		delete_transient( H2MD_QUEUE_TRANSIENT . '_done' );

		wp_send_json_success(
			array(
				'total'   => count( $result['urls'] ),
				'sitemap' => $result['sitemap'],
				'dir'     => $exporter->export_dir(),
			)
		);
	}

	/**
	 * AJAX: process one batch from the queue.
	 */
	public function ajax_process_batch() {
		$this->verify_request();

		$settings = h2md_get_settings();
		$offset   = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$size     = max( 1, (int) $settings['batch_size'] );

		$queue = get_transient( H2MD_QUEUE_TRANSIENT );
		if ( ! is_array( $queue ) ) {
			wp_send_json_error( array( 'message' => __( 'Чергу не знайдено. Спочатку зберіть карту сайту.', 'html-to-markdown' ) ) );
		}

		$slice    = array_slice( $queue, $offset, $size );
		$exporter = new H2MD_Exporter( $settings );

		$done   = get_transient( H2MD_QUEUE_TRANSIENT . '_done' );
		$done   = is_array( $done ) ? $done : array();
		$log    = array();

		foreach ( $slice as $url ) {
			$result = $exporter->export_url( $url );
			if ( is_wp_error( $result ) ) {
				$log[] = array(
					'url'     => $url,
					'status'  => 'error',
					'message' => $result->get_error_message(),
				);
			} else {
				$done[] = $result;
				$log[]  = array(
					'url'    => $url,
					'status' => 'ok',
					'file'   => $result['file'],
				);
			}
		}

		set_transient( H2MD_QUEUE_TRANSIENT . '_done', $done, HOUR_IN_SECONDS );

		$next      = $offset + count( $slice );
		$completed = $next >= count( $queue );

		wp_send_json_success(
			array(
				'processed' => $next,
				'total'     => count( $queue ),
				'completed' => $completed,
				'log'       => $log,
			)
		);
	}

	/**
	 * AJAX: write the index file and clean up.
	 */
	public function ajax_finish() {
		$this->verify_request();

		$settings = h2md_get_settings();
		$exporter = new H2MD_Exporter( $settings );

		$done = get_transient( H2MD_QUEUE_TRANSIENT . '_done' );
		$done = is_array( $done ) ? $done : array();

		$written = $exporter->write_index( $done );

		delete_transient( H2MD_QUEUE_TRANSIENT );
		delete_transient( H2MD_QUEUE_TRANSIENT . '_done' );

		if ( is_wp_error( $written ) ) {
			wp_send_json_error( array( 'message' => $written->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'pages' => count( $done ),
				'index' => trailingslashit( $exporter->export_dir() ) . 'index.md',
			)
		);
	}
}
